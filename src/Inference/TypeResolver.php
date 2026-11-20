<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Inference;

use Closure;
use PhpParser\Comment\Doc;
use PhpParser\Node;
use Vusys\Exocomp\Analysis\Analyser;
use Vusys\Exocomp\Analysis\BodyTypes;
use Vusys\Exocomp\Analysis\Import;
use Vusys\Exocomp\Analysis\LiteralValue;
use Vusys\Exocomp\Framework\Laravel\AuthModel;
use Vusys\Exocomp\Framework\Laravel\Binding;
use Vusys\Exocomp\Index\SymbolGraph;
use Vusys\Exocomp\Index\SymbolRecord;
use Vusys\Exocomp\Project\ClassSource;
use Vusys\Exocomp\Project\InstalledFunctions;
use Vusys\Exocomp\Project\LoadedClass;
use Vusys\Exocomp\Symbol\Confidence;
use Vusys\Exocomp\Symbol\Identifier;
use Vusys\Exocomp\Symbol\SymbolKind;

/**
 * Receiver resolution, scoped hard and stopped deliberately.
 *
 * A general PHP type inference engine is a very large thing — the reference implementation runs
 * to forty thousand lines — and most of that bulk exists to recover types from untyped legacy
 * code. This one targets code where the types are written down, and so it reads only what is
 * written: declared parameter, property and return types, constructor-promoted properties,
 * `$this`/`self`/`static`/`parent`, `new X`, assignment from an already-resolved call, and
 * enum cases. Docblocks are read too, and are always {@see ResolvedType::inferred()}.
 *
 * Then it stops. Anything else returns "unknown" with a reason, and that is a feature rather
 * than a gap: it converts inference from a correctness cliff into a coverage number, and it is
 * what makes it safe to ship at all. A tool that guessed to improve that number would be worse
 * than the grep it replaces, because grep is honestly uncertain and the reader compensates.
 */
final readonly class TypeResolver
{
    /** What a `user()` call returns the signed-in user from, folded; `Auth` is the facade's alias. */
    private const array AUTH_SOURCES = [
        'illuminate\\contracts\\auth\\factory', 'illuminate\\contracts\\auth\\guard', 'illuminate\\contracts\\auth\\statefulguard',
        'illuminate\\auth\\authmanager', 'illuminate\\auth\\sessionguard', 'illuminate\\auth\\requestguard', 'illuminate\\auth\\tokenguard',
        'illuminate\\support\\facades\\auth', 'auth',
    ];

    private DocBlockTypes $docblocks;

    private Members $members;

    private ?EloquentReturns $eloquent;

    /** What {@see beyondTheIndex()} found, per class and method. */
    private TypeMemo $beyond;

    /** Which class beyond the index declares a method, per class and method. */
    private TypeMemo $declaring;

    /**
     * @param  (Closure(string): ?Binding)|null  $boundTo  what the application's providers bind a
     *                                                     class name to, asked only when a
     *                                                     container lookup is actually met
     * @param  ?InstalledFunctions  $installed  the functions installed packages declare, asked
     *                                          when a function is not in the index
     * @param  ?ClassSource  $source  reads a class the index does not hold, so a method declared
     *                                in a framework parent or trait can be found and typed
     * @param  (Closure(): array{?string, string})|null  $authUser  the class the signed-in user is,
     *                                                              or null, and why ({@see AuthModel})
     */
    public function __construct(private SymbolGraph $graph, private ?Magic $magic = null, private ?Closure $boundTo = null, private ?InstalledFunctions $installed = null, private ?ClassSource $source = null, private ?Closure $authUser = null)
    {
        $this->docblocks = new DocBlockTypes;
        $this->members = new Members($graph);
        $this->eloquent = $magic instanceof Magic ? new EloquentReturns($graph, $this->members, $magic) : null;
        $this->beyond = new TypeMemo;
        $this->declaring = new TypeMemo;
    }

    /**
     * Builds the scope for one function body: `$this`, the parameters, and the promoted
     * properties a constructor introduces.
     *
     * A closure is also given what it can see of the scope it is written in, `$enclosing`.
     * Without that, every variable the method had established was lost inside a `map()` or a
     * `transaction()` callback, as if the closure were a method of its own.
     *
     * @param  list<Import>  $imports
     */
    public function scopeFor(?string $classFqn, ?string $parentFqn, ?Node\FunctionLike $function, array $imports, ?Scope $enclosing = null): Scope
    {
        $scope = new Scope($classFqn, $parentFqn);

        if (! $function instanceof Node\FunctionLike) {
            return $scope;
        }

        if ($enclosing instanceof Scope && $function instanceof Node\Expr\ArrowFunction) {
            $scope->inherit($enclosing);
        }

        if ($enclosing instanceof Scope && $function instanceof Node\Expr\Closure) {
            $used = [];

            foreach ($function->uses as $use) {
                if (is_string($use->var->name)) {
                    $used[] = $use->var->name;
                }
            }

            $scope->inherit($enclosing, $used);
        }

        $documented = $this->documented($function);
        $elementsDocumented = null;

        foreach ($function->getParams() as $position => $param) {
            $name = $this->variableName($param->var);

            if ($name === null) {
                continue;
            }

            $declared = self::classLikeTypeOf($param->type);

            // `self $other` and `static $other` are the class being declared, not classes
            // called "self" and "static".
            if ($declared !== null && in_array(strtolower($declared), ['self', 'static'], true)) {
                $declared = $classFqn;
            }

            if ($declared !== null) {
                // `Collection $c` documented as `Collection<int, Order>`: the class, holding Orders.
                $elements = ($elementsDocumented ??= $this->docblocks->elements($function->getDocComment()?->getText() ?? ''))['params'][$name] ?? [];
                $scope->set($name, $elements === []
                    ? ResolvedType::declared($declared)
                    : ResolvedType::declared($declared)->withElements($this->qualifyAll($elements, $classFqn, $imports)));

                continue;
            }

            $fromDoc = $documented['params'][$name] ?? null;

            if ($fromDoc !== null) {
                $elements = ($elementsDocumented ??= $this->docblocks->elements($function->getDocComment()?->getText() ?? ''))['params'][$name] ?? [];
                $type = ResolvedType::inferred($this->qualify($fromDoc, $classFqn, $imports), '@param');
                $scope->set($name, $elements === [] ? $type : $type->withElements($this->qualifyAll($elements, $classFqn, $imports)));

                continue;
            }

            $elements = ($elementsDocumented ??= $this->docblocks->elements($function->getDocComment()?->getText() ?? ''))['params'][$name] ?? [];

            if ($elements !== []) {
                $scope->set($name, ResolvedType::arrayOf($this->qualifyAll($elements, $classFqn, $imports), '@param'));

                continue;
            }

            $hint = $param->type === null && $enclosing instanceof Scope ? $enclosing->callbackHint($function, $position) : null;

            if ($hint instanceof ResolvedType) {
                $scope->set($name, $hint);
            }
        }

        return $scope;
    }

    /**
     * The type of an expression, as far as the rules above allow.
     *
     * @param  list<Import>  $imports
     */
    public function typeOf(Node $expression, Scope $scope, array $imports): ResolvedType
    {
        if ($expression instanceof Node\Expr\CallLike && ! $expression instanceof Node\Expr\New_ && $expression->isFirstClassCallable()) {
            return $this->callableType($expression, $scope, $imports);
        }

        return match (true) {
            $expression instanceof Node\Expr\Variable => $this->variableType($expression, $scope),
            $expression instanceof Node\Expr\New_ => $this->instantiationType($expression),
            $expression instanceof Node\Expr\PropertyFetch,
            $expression instanceof Node\Expr\NullsafePropertyFetch => $this->propertyType($expression, $scope, $imports),
            $expression instanceof Node\Expr\StaticPropertyFetch => $this->staticPropertyType($expression, $scope),
            $expression instanceof Node\Expr\MethodCall,
            $expression instanceof Node\Expr\NullsafeMethodCall => $this->methodReturnType($expression, $scope, $imports),
            $expression instanceof Node\Expr\StaticCall => $this->staticReturnType($expression, $scope),
            $expression instanceof Node\Expr\FuncCall => $this->invocationReturnType($expression, $scope, $imports),
            $expression instanceof Node\Expr\ClassConstFetch => $this->enumCaseType($expression, $scope),
            $expression instanceof Node\Expr\Assign => $this->typeOf($expression->expr, $scope, $imports),
            $expression instanceof Node\Expr\Clone_ => $this->typeOf($expression->expr, $scope, $imports),
            $expression instanceof Node\Expr\Ternary => $this->ternaryType($expression, $scope, $imports),
            $expression instanceof Node\Expr\ArrayDimFetch => $this->elementType($expression, $scope, $imports),
            $expression instanceof Node\Expr\BinaryOp\Coalesce => $this->eitherType($expression->left, $expression->right, $scope, $imports),
            $expression instanceof Node\Expr\AssignOp\Coalesce => $this->coalesceAssignType($expression, $scope, $imports),
            $expression instanceof Node\Expr\Match_ => $this->matchType($expression, $scope, $imports),
            $expression instanceof Node\Expr\BinaryOp\Pipe => $this->pipeType($expression, $scope, $imports),
            default => ResolvedType::unknown('expression kind carries no declared type'),
        };
    }

    /**
     * `$h->make(...)`, `Holder::make(...)`, `strlen(...)`: a `Closure`, not what the method
     * returns. What calling it returns is kept with it, so `$f()` is typed, and so is the
     * method it stands for, so the call `$f()` makes can be named.
     *
     * @param  list<Import>  $imports
     */
    private function callableType(Node\Expr\CallLike $call, Scope $scope, array $imports): ResolvedType
    {
        [$returns, $target] = match (true) {
            $call instanceof Node\Expr\MethodCall, $call instanceof Node\Expr\NullsafeMethodCall => [$this->methodReturnType($call, $scope, $imports), $this->methodTarget($call, $scope, $imports)],
            $call instanceof Node\Expr\StaticCall => [$this->staticReturnType($call, $scope), $this->staticTarget($call, $scope)],
            $call instanceof Node\Expr\FuncCall => [$this->invocationReturnType($call, $scope, $imports), $call->name instanceof Node\Name ? ltrim(Analyser::resolvedName($call->name), '\\') : null],
            default => [null, null],
        };

        return ResolvedType::closure($returns, $target);
    }

    /**
     * @param  list<Import>  $imports
     */
    private function methodTarget(Node\Expr\MethodCall|Node\Expr\NullsafeMethodCall $call, Scope $scope, array $imports): ?string
    {
        $owner = $this->typeOf($call->var, $scope, $imports);

        return $owner->isKnown() && $owner->wrapper === null && $call->name instanceof Node\Identifier
            ? $this->callTarget((string) $owner->fqn, $call->name->toString(), $owner->confidence, $owner->reason)->fqn
            : null;
    }

    private function staticTarget(Node\Expr\StaticCall $call, Scope $scope): ?string
    {
        $owner = $this->className($call->class, $scope);

        return $owner !== null && $call->name instanceof Node\Identifier
            ? $this->callTarget($owner, $call->name->toString(), Confidence::Resolved, 'class name')->fqn
            : null;
    }

    /**
     * `$this->cached ??= new Gateway()`: the left side when its type is known (null is what the
     * right side replaces), and otherwise the right.
     *
     * @param  list<Import>  $imports
     */
    private function coalesceAssignType(Node\Expr\AssignOp\Coalesce $assign, Scope $scope, array $imports): ResolvedType
    {
        $left = $this->typeOf($assign->var, $scope, $imports);

        return $left->isKnown() ? $left : $this->typeOf($assign->expr, $scope, $imports);
    }

    /**
     * A `match` whose arms agree, the rule a ternary follows: an arm that is `null` or a `throw`
     * adds nothing, and the rest must be one class.
     *
     * @param  list<Import>  $imports
     */
    private function matchType(Node\Expr\Match_ $match, Scope $scope, array $imports): ResolvedType
    {
        $found = null;

        foreach ($match->arms as $arm) {
            $body = $arm->body;

            if ($body instanceof Node\Expr\Throw_ || ($body instanceof Node\Expr\ConstFetch && strtolower($body->name->toString()) === 'null')) {
                continue;
            }

            $type = $this->typeOf($body, $scope, $imports);

            if (! $type->isKnown() || ($found instanceof ResolvedType && strcasecmp((string) $found->fqn, (string) $type->fqn) !== 0)) {
                return ResolvedType::unknown('the arms of the match do not agree on a type');
            }

            $found = $found instanceof ResolvedType && $found->confidence !== Confidence::Resolved ? $found : $type;
        }

        return $found ?? ResolvedType::unknown('no arm of the match yields a value');
    }

    /**
     * `$a |> $callable`: what calling the right side with the left returns. That is known for a
     * first-class callable (`$this->m(...)`, `X::m(...)`) and for an arrow function, whose
     * parameter is then the left side.
     *
     * @param  list<Import>  $imports
     */
    private function pipeType(Node\Expr\BinaryOp\Pipe $pipe, Scope $scope, array $imports): ResolvedType
    {
        $callable = $pipe->right;

        if ($callable instanceof Node\Expr\ArrowFunction || $callable instanceof Node\Expr\Closure) {
            $declared = self::classLikeTypeOf($callable->returnType);

            if ($declared !== null) {
                return ResolvedType::declared($declared);
            }

            if (! $callable instanceof Node\Expr\ArrowFunction) {
                return ResolvedType::unknown('the closure declares no class-like return type');
            }

            $inner = $this->scopeFor($scope->classFqn, $scope->parentFqn, $callable, $imports, $scope);
            $first = $callable->params[0] ?? null;
            $name = $first === null ? null : $this->variableName($first->var);

            if ($name !== null && ! $inner->get($name) instanceof ResolvedType) {
                $left = $this->typeOf($pipe->left, $scope, $imports);

                if ($left->isKnown()) {
                    $inner->set($name, $left);
                }
            }

            return $this->typeOf($callable->expr, $inner, $imports);
        }

        $type = $this->typeOf($callable, $scope, $imports);

        return $type->invokes ?? ResolvedType::unknown('the right side of |> is not a callable whose result is known');
    }

    /**
     * `$orders[0]`, on a collection of a known model, is one of its models; `$chain['book']`, on
     * an array documented as `array{book: Book}`, is a Book.
     *
     * @param  list<Import>  $imports
     */
    private function elementType(Node\Expr\ArrayDimFetch $fetch, Scope $scope, array $imports): ResolvedType
    {
        $array = $this->typeOf($fetch->var, $scope, $imports);
        $key = match (true) {
            $fetch->dim instanceof Node\Scalar\String_, $fetch->dim instanceof Node\Scalar\Int_ => (string) $fetch->dim->value,
            default => null,
        };

        return $this->eloquent?->elementOf($array) ?? $array->element($key)
            ?? ResolvedType::unknown('an element of something whose elements are not known');
    }

    /**
     * `$x instanceof Order ? $x : null`, the narrowing written inline. The test is the type of
     * the first branch, as PHP guarantees; otherwise the branches decide between them.
     *
     * @param  list<Import>  $imports
     */
    private function ternaryType(Node\Expr\Ternary $ternary, Scope $scope, array $imports): ResolvedType
    {
        $if = $ternary->if ?? $ternary->cond;

        if ($ternary->cond instanceof Node\Expr\Instanceof_ && $ternary->cond->class instanceof Node\Name
            && $if instanceof Node\Expr\Variable && $ternary->cond->expr instanceof Node\Expr\Variable
            && is_string($if->name) && $if->name === $ternary->cond->expr->name) {
            $class = $this->className($ternary->cond->class, $scope);

            if ($class !== null) {
                return ResolvedType::resolvedBy($class, 'narrowed by instanceof');
            }
        }

        return $this->eitherType($if, $ternary->else, $scope, $imports);
    }

    /**
     * One of two expressions, as in `$a ?? $b` or a ternary's branches: a literal `null` adds
     * nothing, and two branches of one type are that type. Anything else is not known.
     *
     * @param  list<Import>  $imports
     */
    private function eitherType(Node\Expr $first, Node\Expr $second, Scope $scope, array $imports): ResolvedType
    {
        $isNull = static fn (Node\Expr $expression): bool => $expression instanceof Node\Expr\ConstFetch && strtolower($expression->name->toString()) === 'null';

        if ($isNull($second)) {
            return $this->typeOf($first, $scope, $imports);
        }

        if ($isNull($first)) {
            return $this->typeOf($second, $scope, $imports);
        }

        $left = $this->typeOf($first, $scope, $imports);
        $right = $this->typeOf($second, $scope, $imports);

        return $left->isKnown() && $right->isKnown() && strcasecmp((string) $left->fqn, (string) $right->fqn) === 0
            ? ($left->confidence === Confidence::Resolved ? $right : $left)
            : ResolvedType::unknown('the branches do not agree on a type');
    }

    /**
     * What `instanceof` proves on the way into a node. `$x` is a C in the block of
     * `if ($x instanceof C) {…}` (and of an `elseif`), on the right of `$x instanceof C && …`,
     * and in the first branch of `$x instanceof C ? … : …`, and in the arm of a `match (true)`
     * that tests it. It is not one in an `else`, nor before the test in the same condition.
     * `assert($x instanceof C)` proves it for the rest of the body, and a `catch` proves its
     * variable is the caught class.
     *
     * @param  list<Import>  $imports
     */
    public function enterNarrowing(Node $node, Scope $scope, array $imports = []): void
    {
        $scope->entering($node);
        $this->hintCallbacks($node, $scope, $imports);

        if (($node instanceof Node\Stmt\If_ || $node instanceof Node\Stmt\ElseIf_) && $node->stmts !== []) {
            $scope->narrowBetween($node->stmts[0], $node->stmts[array_key_last($node->stmts)], $this->provedBy($node->cond, true, $scope));
        } elseif ($node instanceof Node\Expr\BinaryOp\BooleanAnd || $node instanceof Node\Expr\BinaryOp\LogicalAnd) {
            $scope->narrowBetween($node->right, $node->right, $this->provedBy($node->left, true, $scope));
        } elseif ($node instanceof Node\Expr\Ternary && $node->if instanceof Node\Expr) {
            $scope->narrowBetween($node->if, $node->if, $this->provedBy($node->cond, true, $scope));
        } elseif ($node instanceof Node\Expr\Match_ && $node->cond instanceof Node\Expr\ConstFetch && strtolower($node->cond->name->toString()) === 'true') {
            foreach ($node->arms as $arm) {
                if (count($arm->conds ?? []) === 1) {
                    $scope->narrowBetween($arm->body, $arm->body, $this->provedBy($arm->conds[0], true, $scope));
                }
            }
        } elseif ($node instanceof Node\Stmt\Catch_ && $node->var instanceof Node\Expr\Variable && is_string($node->var->name)) {
            $scope->set($node->var->name, $this->caughtType($node, $scope));
        } elseif ($node instanceof Node\Stmt\Expression && $node->expr instanceof Node\Expr\FuncCall
            && $node->expr->name instanceof Node\Name && strtolower($node->expr->name->toString()) === 'assert'
            && ($node->expr->args[0] ?? null) instanceof Node\Arg) {
            foreach ($this->provedBy($node->expr->args[0]->value, true, $scope) as $variable => $type) {
                $scope->set($variable, $type);
            }
        }
    }

    /**
     * `catch (GatewayException $e)`: the variable is the class the clause names, which PHP
     * guarantees. A clause naming several (`A|B`) leaves it as one of them, which is not one
     * class, so it stays unknown.
     */
    private function caughtType(Node\Stmt\Catch_ $catch, Scope $scope): ResolvedType
    {
        if (count($catch->types) !== 1) {
            return ResolvedType::unknown('caught as one of several types');
        }

        $class = $this->className($catch->types[0], $scope);

        return $class === null
            ? ResolvedType::unknown('caught type unknown')
            : ResolvedType::resolvedBy($class, 'the class the catch names');
    }

    /**
     * Builder methods whose callback is handed the builder, and collection (or builder) methods
     * whose callback is handed one model.
     */
    private const array PASSES_RECEIVER = ['when', 'unless', 'tap', 'where', 'orwhere', 'wherenot', 'orwherenot'];

    private const array PASSES_ELEMENT = [
        'each', 'map', 'filter', 'reject', 'first', 'last', 'sortby', 'sortbydesc', 'groupby', 'keyby', 'sum', 'avg',
        'contains', 'every', 'partition', 'mapwithkeys', 'flatmap', 'pluck', 'transform', 'search', 'firstwhere', 'some',
    ];

    private const array PASSES_CHUNK = ['chunk', 'chunkbyid', 'chunkmap'];

    /**
     * Records what a closure written as an argument will be called with, where the method it
     * is passed to fixes that: `->when($b, fn ($q) => …)` hands on the builder, `->each(fn ($i)
     * => …)` on a collection of a model hands each model, `->chunk(10, fn ($rows) => …)` hands a
     * collection of them, and `tap($x, fn ($y) => …)` hands `$x`. Only an untyped parameter
     * takes it ({@see scopeFor()}), and only the conventions listed, inferred.
     *
     * @param  list<Import>  $imports
     */
    private function hintCallbacks(Node $node, Scope $scope, array $imports): void
    {
        if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name && Analyser::isGlobalFunction($node->name, 'tap')) {
            $args = Analyser::arguments($node);
            $callback = $args[1]->value ?? null;

            if (($callback instanceof Node\Expr\Closure || $callback instanceof Node\Expr\ArrowFunction) && isset($args[0])) {
                $snapshot = clone $scope;
                $value = $args[0]->value;
                $scope->hintCallback($callback, 0, fn (): ?ResolvedType => ($type = $this->typeOf($value, $snapshot, $imports))->isKnown() ? $type : null);
            }

            return;
        }

        if ((! $node instanceof Node\Expr\MethodCall && ! $node instanceof Node\Expr\NullsafeMethodCall) || ! $node->name instanceof Node\Identifier) {
            return;
        }

        $method = strtolower($node->name->toString());

        if (! in_array($method, [...self::PASSES_RECEIVER, ...self::PASSES_ELEMENT, ...self::PASSES_CHUNK], true)) {
            return;
        }

        $snapshot = clone $scope;
        $receiver = $node->var;
        $owner = (fn (): ResolvedType => $this->typeOf($receiver, $snapshot, $imports));

        foreach (Analyser::arguments($node) as $argument) {
            $callback = $argument->value;

            if (! $callback instanceof Node\Expr\Closure && ! $callback instanceof Node\Expr\ArrowFunction) {
                continue;
            }

            $scope->hintCallback($callback, 0, function () use ($owner, $method): ?ResolvedType {
                $type = $owner();

                if (! $type->isKnown()) {
                    return null;
                }

                if (in_array($method, self::PASSES_RECEIVER, true)) {
                    return $type->wrapper === EloquentReturns::BUILDER || $method === 'tap' ? $type : null;
                }

                // `Collection<int, Order>` from a docblock: its callbacks are handed an Order.
                if ($type->model === null) {
                    return in_array($method, self::PASSES_ELEMENT, true) ? $type->element(null) : null;
                }

                if (in_array($method, self::PASSES_CHUNK, true)) {
                    return $type->wrapper === EloquentReturns::BUILDER
                        ? ResolvedType::wrapping('Illuminate\\Database\\Eloquent\\Collection', EloquentReturns::COLLECTION, $type->model, sprintf('a chunk of %s, by Eloquent convention', $type->model))
                        : null;
                }

                return in_array($type->wrapper, [EloquentReturns::COLLECTION, EloquentReturns::BUILDER], true)
                    ? ResolvedType::inferred($type->model, sprintf('a %s, as %s() hands its callback, by Eloquent convention', $type->model, $method))
                    : null;
            });
        }
    }

    /**
     * What `instanceof` proves on the way out of an `if`. A guard such as
     * `if (! $x instanceof C) { throw … }` with no other branch leaves only the path where `$x`
     * is a C, so it is one for the rest of the body.
     */
    public function leaveNarrowing(Node $node, Scope $scope): void
    {
        $scope->leaving($node);

        if (! $node instanceof Node\Stmt\If_) {
            return;
        }

        if ($node->elseifs !== [] || $node->else instanceof Node\Stmt\Else_ || ! $this->leaves($node->stmts)) {
            return;
        }

        foreach ($this->provedBy($node->cond, false, $scope) as $variable => $type) {
            $scope->set($variable, $type);
        }
    }

    /**
     * The variables a condition proves to be of a class when it is true (or, for `$when` false,
     * when it is false): `$x instanceof C`, through `&&` when true and `||` and `!` when false.
     *
     * @return array<string, ResolvedType>
     */
    private function provedBy(Node\Expr $condition, bool $when, Scope $scope): array
    {
        if ($condition instanceof Node\Expr\BooleanNot) {
            return $this->provedBy($condition->expr, ! $when, $scope);
        }

        if (($when && ($condition instanceof Node\Expr\BinaryOp\BooleanAnd || $condition instanceof Node\Expr\BinaryOp\LogicalAnd))
            || (! $when && ($condition instanceof Node\Expr\BinaryOp\BooleanOr || $condition instanceof Node\Expr\BinaryOp\LogicalOr))) {
            return $this->provedBy($condition->right, $when, $scope) + $this->provedBy($condition->left, $when, $scope);
        }

        if (! $when || ! $condition instanceof Node\Expr\Instanceof_
            || ! $condition->expr instanceof Node\Expr\Variable || ! is_string($condition->expr->name)) {
            return [];
        }

        $class = $this->className($condition->class, $scope);

        return $class === null ? [] : [$condition->expr->name => ResolvedType::resolvedBy($class, 'narrowed by instanceof')];
    }

    /**
     * Whether a block always leaves the code that follows it: it ends in a `return`, a
     * `throw`, a `continue`, a `break` or an `exit`.
     *
     * @param  array<Node\Stmt>  $statements
     */
    private function leaves(array $statements): bool
    {
        $last = end($statements);

        return $last instanceof Node\Stmt\Return_ || $last instanceof Node\Stmt\Continue_ || $last instanceof Node\Stmt\Break_
            || ($last instanceof Node\Stmt\Expression && ($last->expr instanceof Node\Expr\Throw_ || $last->expr instanceof Node\Expr\Exit_));
    }

    /**
     * Records the type of a `foreach` value variable, where the iterable is something whose
     * elements are known: a collection of a model, as Eloquent hands back, or an array a
     * docblock documents.
     *
     * @param  list<Import>  $imports
     */
    public function observeForeach(Node\Stmt\Foreach_ $foreach, Scope $scope, array $imports): void
    {
        $name = $this->variableName($foreach->valueVar);

        if ($name === null) {
            return;
        }

        $snapshot = clone $scope;

        $scope->setLazy($name, function () use ($foreach, $snapshot, $imports, $name): ?ResolvedType {
            $iterable = $this->typeOf($foreach->expr, $snapshot, $imports);

            return $this->eloquent?->elementOf($iterable) ?? $iterable->element(null) ?? $snapshot->get($name);
        });
    }

    /**
     * Records what an assignment establishes, so a later use of the variable resolves.
     *
     * @param  list<Import>  $imports
     */
    public function observeAssignment(Node\Expr\Assign $assign, Scope $scope, array $imports): void
    {
        if ($assign->var instanceof Node\Expr\Array_ || $assign->var instanceof Node\Expr\List_) {
            $this->observeDestructuring($assign->var, $assign->expr, $scope, $imports);

            return;
        }

        $name = $this->variableName($assign->var);

        if ($name === null) {
            return;
        }

        // Worked out only when the variable is used, against the scope as it stood here — a
        // copy, so a later assignment (`$a = $a->next()`) reads the binding before it. An
        // assignment that establishes nothing leaves the earlier binding, as it always has.
        $snapshot = clone $scope;
        $documented = $this->docblockOf($assign);

        $scope->setLazy($name, function () use ($assign, $snapshot, $imports, $documented, $name): ?ResolvedType {
            $type = $this->typeOf($assign->expr, $snapshot, $imports);

            if ($type->isKnown() || $type->elements !== []) {
                return $type;
            }

            $fromDoc = $documented === null ? null : ($this->docblocks->read($documented)['var'] ?? null);

            if ($fromDoc !== null) {
                return ResolvedType::inferred($this->qualify($fromDoc, $snapshot->classFqn, $imports), '@var');
            }

            $elements = $documented === null ? [] : $this->docblocks->elements($documented)['var'];

            return $elements !== []
                ? ResolvedType::arrayOf($this->qualifyAll($elements, $snapshot->classFqn, $imports), '@var')
                : $snapshot->get($name);
        });
    }

    /**
     * `['inv' => $x] = $this->pair()` and `[$a, $b] = $list`: each variable is the element the
     * right side documents at that key or position, as `$this->pair()['inv']` already is.
     *
     * @param  list<Import>  $imports
     */
    private function observeDestructuring(Node\Expr\Array_|Node\Expr\List_ $pattern, Node\Expr $value, Scope $scope, array $imports): void
    {
        $snapshot = clone $scope;

        foreach ($pattern->items as $position => $item) {
            $name = $item === null ? null : $this->variableName($item->value);

            if ($item === null || $name === null) {
                continue;
            }

            $key = match (true) {
                $item->key instanceof Node\Scalar\String_, $item->key instanceof Node\Scalar\Int_ => (string) $item->key->value,
                $item->key === null => (string) $position,
                default => null,
            };

            $scope->setLazy($name, function () use ($value, $snapshot, $imports, $key, $name): ?ResolvedType {
                $type = $this->typeOf($value, $snapshot, $imports);

                return $this->eloquent?->elementOf($type) ?? $type->element($key) ?? $snapshot->get($name);
            });
        }
    }

    private function variableType(Node\Expr\Variable $variable, Scope $scope): ResolvedType
    {
        if (! is_string($variable->name)) {
            return ResolvedType::unknown('variable variable');
        }

        if ($variable->name === 'this') {
            return $scope->classFqn === null
                ? ResolvedType::unknown('$this outside a class')
                : ResolvedType::resolvedBy($scope->classFqn, '$this');
        }

        return $scope->get($variable->name) ?? ResolvedType::unknown('receiver type unknown');
    }

    private function instantiationType(Node\Expr\New_ $new): ResolvedType
    {
        if (! $new->class instanceof Node\Name) {
            return ResolvedType::unknown('variable class name');
        }

        return ResolvedType::resolvedBy(Analyser::resolvedName($new->class), 'new');
    }

    /**
     * @param  list<Import>  $imports
     */
    private function propertyType(Node\Expr\PropertyFetch|Node\Expr\NullsafePropertyFetch $fetch, Scope $scope, array $imports): ResolvedType
    {
        if (! $fetch->name instanceof Node\Identifier) {
            return ResolvedType::unknown('variable property name');
        }

        $owner = $this->typeOf($fetch->var, $scope, $imports);

        if (! $owner->isKnown()) {
            return ResolvedType::unknown($owner->reason);
        }

        $name = $fetch->name->toString();
        $member = $this->member((string) $owner->fqn, $name, SymbolKind::Property);

        if ($member instanceof SymbolRecord) {
            return $this->memberType($member, (string) $owner->fqn)
                ?? ResolvedType::unknown('property has no declared class type');
        }

        return $this->eloquent?->fromRelationProperty((string) $owner->fqn, $name)
            ?? $this->magically((string) $owner->fqn, $name, SymbolKind::Property)
            ?? ResolvedType::unknown($this->graph->classLike((string) $owner->fqn) instanceof SymbolRecord
                ? 'property has no declared class type'
                : sprintf('%s is not in the index, so its properties are not known', $owner->fqn));
    }

    private function staticPropertyType(Node\Expr\StaticPropertyFetch $fetch, Scope $scope): ResolvedType
    {
        $owner = $this->className($fetch->class, $scope);

        if ($owner === null || ! $fetch->name instanceof Node\VarLikeIdentifier) {
            return ResolvedType::unknown('static property on an unknown class');
        }

        $member = $this->member($owner, $fetch->name->toString(), SymbolKind::Property);

        return ($member instanceof SymbolRecord ? $this->memberType($member, $owner) : null)
            ?? ResolvedType::unknown('static property has no declared class type');
    }

    /**
     * @param  list<Import>  $imports
     */
    private function methodReturnType(Node\Expr\MethodCall|Node\Expr\NullsafeMethodCall $call, Scope $scope, array $imports): ResolvedType
    {
        if (! $call->name instanceof Node\Identifier) {
            return ResolvedType::unknown('variable method name');
        }

        $owner = $this->typeOf($call->var, $scope, $imports);
        $name = $call->name->toString();

        // A builder, factory or collection of a model: a framework class the index does not
        // hold, so the next link is read off Eloquent's API rather than looked up.
        if ($owner->wrapper !== null) {
            return $this->eloquent?->step($owner, $name, $call)
                ?? ResolvedType::unknown(sprintf('%s() on %s is not a method this tool knows the result of', $name, $owner->wrapper));
        }

        $type = $owner->isKnown()
            ? $this->returnTypeOf((string) $owner->fqn, $name)
            : ResolvedType::unknown($owner->reason);

        if ($owner->isKnown() && (! $type->isKnown() || $type->confidence !== Confidence::Resolved)) {
            $type = $this->eloquent?->fromInstance((string) $owner->fqn, $name) ?? $type;
        }

        // A relation method declared to return one of the framework's relation classes: that
        // class knows nothing of the related model, and the method's body does. One declaring
        // no return type at all, as relations did before Laravel 9's stubs, is read the same way.
        if ($owner->isKnown() && (($type->isKnown() && str_starts_with((string) $type->fqn, 'Illuminate\\Database\\Eloquent\\Relations\\')) || ! $type->isKnown())) {
            $type = $this->eloquent?->fromRelation((string) $owner->fqn, $name) ?? $type;
        }

        if ($owner->isKnown() && ! $type->isKnown()) {
            $type = $this->authenticatedUser((string) $owner->fqn, $name) ?? $type;
        }

        $type = $this->withReceiverElements($owner, $name, $type);

        // `$this->createMock(Gateway::class)`: a double of the class named, which is what the
        // test calls methods on. PHPUnit declares `MockObject&RealInstanceType`, and the native
        // `MockObject` alone named members that do not exist.
        if (in_array(strtolower($name), ['createmock', 'createstub', 'createpartialmock', 'createconfiguredmock', 'createconfiguredstub', 'getmockforabstractclass', 'createtestproxy'], true)) {
            $double = $this->doubleOf($call, $scope);

            if ($double instanceof ResolvedType) {
                return $double;
            }
        }

        // `app()->make(X::class)`, `$this->app->make(X::class)` and friends: the container's own
        // signature says `mixed`, so this only steps in where nothing declared said otherwise.
        if (! $type->isKnown() && in_array(strtolower($call->name->toString()), ['make', 'makewith'], true)) {
            return $this->containerLookup($call, $scope) ?? $type;
        }

        return $type;
    }

    /** Collection methods that return one of what the collection holds. */
    private const array RETURNS_AN_ELEMENT = ['first', 'last', 'get', 'pop', 'shift', 'pull', 'sole', 'firstwhere', 'firstorfail', 'random', 'find', 'current', 'offsetget'];

    /** Collection methods that return a collection of the same elements. */
    private const array KEEPS_THE_ELEMENTS = [
        'filter', 'reject', 'sortby', 'sortbydesc', 'sort', 'sortdesc', 'values', 'unique', 'take', 'skip', 'slice', 'reverse',
        'each', 'except', 'only', 'diff', 'intersect', 'shuffle', 'keyby', 'merge', 'concat', 'push', 'prepend', 'where',
        'wherein', 'wherenotin', 'wherenull', 'wherenotnull', 'whereinstanceof', 'tap', 'when', 'unless', 'collect', 'all',
    ];

    /**
     * What a receiver documented to hold something (`Collection<int, Order>`) passes on: an
     * element where the method returns one, and its elements where the method returns the same
     * collection back.
     */
    private function withReceiverElements(ResolvedType $owner, string $method, ResolvedType $type): ResolvedType
    {
        if ($owner->elements === []) {
            return $type->receiverElement ? ResolvedType::unknown('returns what the receiver holds, and the receiver does not say what that is') : $type;
        }

        $name = strtolower($method);

        if ($type->receiverElement || (! $type->isKnown() && in_array($name, self::RETURNS_AN_ELEMENT, true))) {
            return $owner->element(null) ?? $type;
        }

        if (in_array($name, self::KEEPS_THE_ELEMENTS, true) && ($type->isKnown() ? strcasecmp((string) $type->fqn, (string) $owner->fqn) === 0 : true) && $owner->isKnown()) {
            return ($type->isKnown() ? $type : ResolvedType::inferred((string) $owner->fqn, sprintf('%s() keeps what the collection holds', $method)))->withElements($owner->elements);
        }

        return $type;
    }

    private function doubleOf(Node\Expr\CallLike $call, Scope $scope): ?ResolvedType
    {
        $first = Analyser::arguments($call)[0]->value ?? null;
        $class = $first instanceof Node\Expr\ClassConstFetch ? LiteralValue::className($first, $scope->classFqn) : null;

        return $class === null ? null : ResolvedType::inferred($class, sprintf('a test double of %s', $class));
    }

    private function staticReturnType(Node\Expr\StaticCall $call, Scope $scope): ResolvedType
    {
        $owner = $this->className($call->class, $scope);

        if ($owner === null || ! $call->name instanceof Node\Identifier) {
            return ResolvedType::unknown('static call on an unknown class');
        }

        // `Mockery::mock(Gateway::class)` and `Mockery::spy()`, the other common double.
        if (strcasecmp(ltrim($owner, '\\'), 'Mockery') === 0 && in_array(strtolower($call->name->toString()), ['mock', 'spy', 'namedmock'], true)) {
            $double = $this->doubleOf($call, $scope);

            if ($double instanceof ResolvedType) {
                return $double;
            }
        }

        $type = $this->returnTypeOf($owner, $call->name->toString());

        // Eloquent's convention outranks an annotation, never a declaration. The generated
        // `@method static Builder|Order query()` blocks name the bare framework class, which
        // knows nothing of the model, so read literally they ended every chain at `query()`.
        if (! $type->isKnown() || $type->confidence !== Confidence::Resolved) {
            $type = $this->eloquent?->fromModel($owner, $call->name->toString(), $call) ?? $type;
        }

        if (! $type->isKnown()) {
            $type = $this->authenticatedUser($owner, $call->name->toString()) ?? $type;
        }

        // `App::make(X::class)` — the facade, by its short name or its alias.
        if (! $type->isKnown()
            && in_array(strtolower($call->name->toString()), ['make', 'makewith'], true)
            && ($owner === 'App' || str_ends_with($owner, '\\Facades\\App'))) {
            return $this->containerLookup($call, $scope) ?? $type;
        }

        return $type;
    }

    /**
     * A class pulled out of the container by name is an instance of that name, or of whatever
     * the application's providers bind it to.
     *
     * This is the commonest way a Laravel application reaches a service, and the container's
     * signature is `mixed` because it has to be — so without this, every one of those calls is
     * unresolved for want of a fact written right there in the argument. It does not need the
     * framework's source: the argument is the answer.
     */
    private function containerLookup(Node\Expr\FuncCall|Node\Expr\MethodCall|Node\Expr\NullsafeMethodCall|Node\Expr\StaticCall $call, Scope $scope): ?ResolvedType
    {
        $argument = Analyser::arguments($call)[0]->value ?? null;
        $class = $argument instanceof Node\Expr\ClassConstFetch ? LiteralValue::className($argument, $scope->classFqn) : null;

        if ($class === null || in_array(strtolower($class), ['self', 'static', 'parent'], true)) {
            return null;
        }

        $binding = $this->boundTo instanceof Closure ? ($this->boundTo)($class) : null;

        if ($binding instanceof Binding && $binding->concrete !== null) {
            return ResolvedType::of($binding->concrete, Confidence::weakest($binding->confidence, Confidence::Inferred), sprintf('resolved out of the container, where %s is bound to it', $class));
        }

        return ResolvedType::resolvedBy($class, 'resolved out of the container by class name');
    }

    /**
     * `auth()->user()`, `Auth::user()`, `$request->user()`: the framework declares a contract, and
     * the app's auth config names the model behind it.
     */
    private function authenticatedUser(string $ownerFqn, string $method): ?ResolvedType
    {
        if (strtolower($method) !== 'user' || ! $this->authUser instanceof Closure) {
            return null;
        }

        $fold = Identifier::fold($ownerFqn);

        if (! in_array($fold, self::AUTH_SOURCES, true) && $this->source?->descendsFrom($ownerFqn, 'Illuminate\\Http\\Request') !== true) {
            return null;
        }

        [$model, $why] = ($this->authUser)();

        return $model === null
            ? ResolvedType::unknown(sprintf('user() is the signed-in user, but %s', $why))
            : ResolvedType::inferred($model, sprintf('the signed-in user, %s', $why));
    }

    private function returnTypeOf(string $ownerFqn, string $method): ResolvedType
    {
        $member = $this->member($ownerFqn, $method, SymbolKind::Method);

        if (! $member instanceof SymbolRecord) {
            if ($this->isEnumIntrinsic($ownerFqn, $method)) {
                return strtolower($method) === 'cases'
                    ? ResolvedType::arrayOf(['*' => $ownerFqn], 'the language: cases() lists the enum')
                    : ResolvedType::resolvedBy($ownerFqn, sprintf('%s() is given to every backed enum by the language', $method));
            }

            return $this->magically($ownerFqn, $method, SymbolKind::Method)
                ?? $this->beyondTheIndex($ownerFqn, $method)
                ?? ResolvedType::unknown($this->whyNoMethod($ownerFqn, $method));
        }

        return $this->memberType($member, $ownerFqn)
            ?? ResolvedType::unknown('method declares no class-like return type');
    }

    /**
     * A property's or method's declared class, or failing that the one its class's code or
     * docblock implies (BodyTypes), which is only ever inferred.
     *
     * A fluent method declares `static` or `self`, which name a class only relative to the call:
     * `static` is the receiver's class, `self` the class that declares the method. Taken
     * literally, they named a class called "static" and every chain stopped there.
     */
    private function memberType(SymbolRecord $member, string $ownerFqn): ?ResolvedType
    {
        if ($member->inferredFqn === BodyTypes::RECEIVER_ELEMENT) {
            return ResolvedType::receiverElement((string) $member->inferredReason);
        }

        // A native `self` the docblock narrows to `static` is the receiver's class.
        $narrowed = $member->typeFqn !== null && strtolower($member->typeFqn) === 'self' && strtolower((string) $member->inferredFqn) === 'static';

        if ($member->typeFqn !== null && ! $narrowed) {
            $declared = ResolvedType::declared($this->relativeTo($member->typeFqn, $member, $ownerFqn));

            return $member->inferredElements === [] ? $declared : $declared->withElements($member->inferredElements);
        }

        if ($member->inferredFqn !== null) {
            return ResolvedType::inferred($this->relativeTo($member->inferredFqn, $member, $ownerFqn), (string) $member->inferredReason);
        }

        return $member->inferredElements === []
            ? null
            : ResolvedType::arrayOf($member->inferredElements, $member->kind === SymbolKind::Method ? '@return' : '@var');
    }

    private function relativeTo(string $type, SymbolRecord $member, string $ownerFqn): string
    {
        return match (strtolower($type)) {
            'static' => $ownerFqn,
            // `self` in a trait is the class that uses it, as `static` is; the trait itself is
            // never an instance of anything.
            'self' => $member->owner === null || $this->isTrait($member->owner) ? $ownerFqn : $member->owner,
            default => $type,
        };
    }

    private function isTrait(string $fqn): bool
    {
        return $this->graph->classLike($fqn)?->kind === SymbolKind::TraitType;
    }

    /**
     * An enum case is an instance of its enum — the one place where a constant fetch names a
     * type rather than a value.
     */
    /**
     * Calling a value invokes `__invoke` on its class, so the expression's type is that
     * method's declared return type.
     *
     * @param  list<Import>  $imports
     */
    private function invocationReturnType(Node\Expr\FuncCall $call, Scope $scope, array $imports): ResolvedType
    {
        if ($call->name instanceof Node\Name) {
            // `clone($x, [...])`, PHP 8.5's clone with: a copy of `$x`, like `clone $x`.
            if (strtolower($call->name->toString()) === 'clone' && ($first = Analyser::arguments($call)[0] ?? null) instanceof Node\Arg) {
                return $this->typeOf($first->value, $scope, $imports);
            }

            // `tap($x, …)` returns `$x`, whatever the callback does.
            if (Analyser::isGlobalFunction($call->name, 'tap') && ($first = Analyser::arguments($call)[0] ?? null) instanceof Node\Arg) {
                return $this->typeOf($first->value, $scope, $imports);
            }

            // `app()` with no argument is the application itself, by the helper's own signature.
            if (Analyser::isGlobalFunction($call->name, 'app') && Analyser::arguments($call) === []) {
                return ResolvedType::resolvedBy('Illuminate\\Foundation\\Application', 'app() with no argument returns the application');
            }

            if ((Analyser::isGlobalFunction($call->name, 'app') || Analyser::isGlobalFunction($call->name, 'resolve'))
                && ($found = $this->containerLookup($call, $scope)) instanceof ResolvedType) {
                return $found;
            }

            return $this->functionReturnType($call->name, $call);
        }

        $receiver = $this->typeOf($call->name, $scope, $imports);

        if (! $receiver->isKnown()) {
            return ResolvedType::unknown($receiver->reason);
        }

        if ($receiver->invokes instanceof ResolvedType) {
            return $receiver->invokes;
        }

        if (strcasecmp((string) $receiver->fqn, 'Closure') === 0) {
            return ResolvedType::unknown('what the closure returns is not known');
        }

        return $this->returnTypeOf((string) $receiver->fqn, '__invoke');
    }

    private function enumCaseType(Node\Expr\ClassConstFetch $fetch, Scope $scope): ResolvedType
    {
        $owner = $this->className($fetch->class, $scope);

        if ($owner === null || ! $fetch->name instanceof Node\Identifier) {
            return ResolvedType::unknown('class constant on an unknown class');
        }

        $declaring = $this->graph->classLike($owner);

        if ($declaring?->kind !== SymbolKind::EnumType) {
            return ResolvedType::unknown('class constants carry no type');
        }

        $case = $this->member($owner, $fetch->name->toString(), SymbolKind::EnumCase);

        return $case instanceof SymbolRecord
            ? ResolvedType::resolvedBy($owner, 'enum case')
            : ResolvedType::unknown('no such enum case');
    }

    private function className(Node $class, Scope $scope): ?string
    {
        if (! $class instanceof Node\Name) {
            return null;
        }

        $keyword = $scope->resolveKeyword($class->toString());

        return $keyword instanceof ResolvedType ? $keyword->fqn : Analyser::resolvedName($class);
    }

    /**
     * Members are looked up through the hierarchy, because a property declared on a parent is
     * still the receiver's property — resolving only against a class's own declarations would
     * fail on the ordinary case of anything with a base class.
     */
    /**
     * "Method not found" is two different statements and only one of them is about the code.
     *
     * A class whose ancestry runs off the end of the indexed source has an API far larger than
     * its own declarations — an Eloquent model's whole query interface arrives through a magic
     * `__call` on a base class in `vendor/` — and reporting an undeclared method there as "not
     * found" reads as "this call is broken". It is not: it is a call the tool cannot see the
     * far end of, which is a fact about the tool.
     */
    /**
     * A method the index does not hold, found where the class's ancestry leaves the corpus: a
     * framework parent or trait, read from `vendor/`. `$this->guard()` in a controller using
     * `AuthenticatesUsers` is declared there, and its `@return` says what it gives back.
     *
     * A single native class type is the answer; failing that, a single class in `@return`, which
     * is a claim and so `inferred`. Anything else leaves the call unknown, as before.
     */
    private function beyondTheIndex(string $ownerFqn, string $method): ?ResolvedType
    {
        if (! $this->source instanceof ClassSource) {
            return null;
        }

        $key = Identifier::fold($ownerFqn.'::'.$method);

        if ($this->beyond->has($key)) {
            return $this->beyond->get($key);
        }

        // A model's framework methods are Eloquent's, which {@see EloquentReturns} answers by
        // convention; walking `Model` and its traits for each one cost more than it found.
        if ($this->magic?->isModel($ownerFqn) === true || $this->members->isComplete($ownerFqn)) {
            return $this->beyond->set($key, null);
        }

        return $this->beyond->set($key, $this->declaredBeyondTheIndex($this->source, $ownerFqn, $method));
    }

    private function declaredBeyondTheIndex(ClassSource $source, string $ownerFqn, string $method): ?ResolvedType
    {
        foreach ($source->withAncestors($ownerFqn) as $class) {
            $node = $class->node->getMethod($method);

            if (! $node instanceof Node\Stmt\ClassMethod) {
                continue;
            }

            $native = self::classLikeTypeOf($node->getReturnType());

            if ($native !== null) {
                return match (strtolower($native)) {
                    'static' => ResolvedType::declared($ownerFqn),
                    'self' => ResolvedType::declared($class->node instanceof Node\Stmt\Trait_ ? $ownerFqn : $class->fqn),
                    default => ResolvedType::declared($native),
                };
            }

            $documented = $this->documented($node)['return'] ?? null;
            $templates = $this->docblocks->templates($class->node->getDocComment()?->getText() ?? '');

            if (is_string($documented) && in_array($documented, $templates, true)) {
                return $documented === $templates[array_key_last($templates)]
                    ? ResolvedType::receiverElement(sprintf('%s::%s() returns %s, what the collection holds', $class->fqn, $method, $documented))
                    : ResolvedType::unknown(sprintf('%s::%s() returns the template %s', $class->fqn, $method, $documented));
            }

            if (is_string($documented) && in_array($documented, $this->docblocks->templates($node->getDocComment()?->getText() ?? ''), true)) {
                return ResolvedType::unknown(sprintf('%s::%s() returns the template %s', $class->fqn, $method, $documented));
            }

            // `@return static<TKey, TValue>`, the way a fluent collection method is documented.
            if (is_string($documented) && in_array(strtolower((string) preg_replace('/<.*$/', '', $documented)), ['static', '$this'], true)) {
                return ResolvedType::inferred($ownerFqn, sprintf('the @return of %s::%s(), read where it is declared', $class->fqn, $method));
            }

            if (is_string($documented) && ! in_array(strtolower($documented), ['static', 'self', '$this'], true)) {
                return ResolvedType::inferred(
                    $this->qualify($documented, $class->fqn, $class->analysis->imports),
                    sprintf('the @return of %s::%s(), read where it is declared', $class->fqn, $method),
                );
            }

            return ResolvedType::unknown(sprintf('%s::%s(), read where it is declared, returns no single class', $class->fqn, $method));
        }

        return null;
    }

    /**
     * Whether a class name is declared nowhere: not in the index, not where Composer would put
     * it, not by PHP, while its namespace is one the index holds code in, so the absence is
     * not just a dependency that was never installed.
     */
    public function existsNowhere(string $fqn): bool
    {
        $fqn = ltrim($fqn, '\\');
        $position = strrpos($fqn, '\\');

        if ($position === false || $this->graph->classLike($fqn) instanceof SymbolRecord
            || class_exists($fqn, false) || interface_exists($fqn, false) || trait_exists($fqn, false) || enum_exists($fqn, false)) {
            return false;
        }

        $key = '@exists:'.Identifier::fold($fqn);

        if (! $this->declaring->has($key)) {
            $missing = $this->source instanceof ClassSource && ! $this->source->load($fqn) instanceof LoadedClass
                && $this->graph->holdsNamespace(mb_strcut($fqn, 0, $position));
            $this->declaring->set($key, $missing ? ResolvedType::unknown('missing') : null);
        }

        return $this->declaring->get($key) instanceof ResolvedType;
    }

    /**
     * The class in `$ownerFqn`'s ancestry, read from source, that declares `$method`.
     */
    private function declaringBeyondTheIndex(string $ownerFqn, string $method): ?string
    {
        if (! $this->source instanceof ClassSource) {
            return null;
        }

        $key = Identifier::fold($ownerFqn.'::'.$method);

        if (! $this->declaring->has($key)) {
            $found = null;

            foreach ($this->source->withAncestors($ownerFqn) as $class) {
                if ($class->node->getMethod($method) instanceof Node\Stmt\ClassMethod) {
                    $found = ResolvedType::resolvedBy($class->fqn, 'declared');

                    break;
                }
            }

            $this->declaring->set($key, $found);
        }

        return $this->declaring->get($key)?->fqn;
    }

    private function whyNoMethod(string $ownerFqn, string $method): string
    {
        // A class that declares `__call` answers to names it does not declare, so "declares no
        // X()" reads as a broken call when it is really a wall. Which of the two it is changes
        // what a reader should do about it, so the answer has to distinguish them.
        if ($this->member($ownerFqn, '__call', SymbolKind::Method) instanceof SymbolRecord) {
            return sprintf('%s declares no %s(), and declares __call(), so this resolves at runtime through that', $ownerFqn, $method);
        }

        if (! $this->graph->classLike($ownerFqn) instanceof SymbolRecord) {
            return sprintf('%s is not in the index, and its source could not be read for %s()', $ownerFqn, $method);
        }

        $unreachable = $this->graph->members($ownerFqn)['unreachable'];

        if ($unreachable === []) {
            return sprintf('%s declares no %s()', $ownerFqn, $method);
        }

        return sprintf(
            '%s declares no %s(), and its ancestry leaves the indexed source at %s — the method may be declared there or forwarded by a magic __call',
            $ownerFqn,
            $method,
            $unreachable[0]->fqn,
        );
    }

    /**
     * What a call site actually calls, once the receiver's type is known.
     *
     * Knowing the receiver was only ever half the question. Reporting `Receiver::whateverWasTyped`
     * without checking the member exists is right for code that compiles and confidently wrong
     * for the rest — and "the rest" is where a framework application spends its time.
     */
    /**
     * What a call on a builder of a model runs when the model declares it as a local scope.
     */
    public function scopeTarget(ResolvedType $receiver, string $name): ?CallTarget
    {
        if ($receiver->wrapper !== EloquentReturns::BUILDER || $receiver->model === null) {
            return null;
        }

        $declaration = $this->eloquent?->scopeDeclaration($receiver->model, $name);

        return $declaration === null ? null : CallTarget::at($declaration, Confidence::Inferred, sprintf('a local scope of %s, called on its query builder', $receiver->model));
    }

    public function callTarget(string $ownerFqn, string $name, Confidence $confidence, string $reason): CallTarget
    {
        // A relation: the method is the relation's own where it declares one (`save`, `create`,
        // `associate`, which set the foreign key), and otherwise the query builder's, where
        // `Relation::__call` forwards it.
        if (str_starts_with($ownerFqn, 'Illuminate\\Database\\Eloquent\\Relations\\')) {
            $declaring = $this->declaringBeyondTheIndex($ownerFqn, $name);

            return $declaring !== null
                ? CallTarget::at($declaring.'::'.$name, $confidence, $reason)
                : CallTarget::at('Illuminate\\Database\\Eloquent\\Builder::'.$name, Confidence::weakest($confidence, Confidence::Inferred), $reason.'; forwarded to the query builder by Relation::__call');
        }

        // `Closure` is final and its methods are the language's, so anything else is not there.
        if (strcasecmp(ltrim($ownerFqn, '\\'), 'Closure') === 0) {
            return in_array(strtolower($name), ['bind', 'bindto', 'call', 'fromcallable', '__invoke'], true)
                ? CallTarget::at('Closure::'.$name, $confidence, $reason)
                : CallTarget::none(sprintf('a Closure has no %s() — it is a first-class callable, not the object its method returns', $name));
        }

        if ($this->member($ownerFqn, $name, SymbolKind::Method) instanceof SymbolRecord) {
            return CallTarget::at($ownerFqn.'::'.$name, $confidence, $reason);
        }

        $magic = $this->magic?->method($ownerFqn, $name);

        if ($magic instanceof MagicMember) {
            return CallTarget::at(
                $magic->declaration ?? $ownerFqn.'::'.$name,
                Confidence::weakest($confidence, $magic->confidence),
                $magic->reason,
            );
        }

        // A model's call that nothing in its ancestry declares, `Order::find()` or `Order::where()`,
        // runs through `__call`/`__callStatic` on the query builder. The address stays the
        // model's, where a reader looks for it, but it is a convention, not a declaration.
        // Only where the ancestry was read as far as the `__call` that forwards: with no `vendor/`
        // to read, an absence proves nothing.
        if ($this->magic?->isModel($ownerFqn) === true && $this->declaringBeyondTheIndex($ownerFqn, $name) === null
            && ($builder = $this->eloquent?->builderOf($ownerFqn)) !== null && $this->members->declared($builder, $name, SymbolKind::Method) instanceof SymbolRecord) {
            return CallTarget::at($builder.'::'.$name, Confidence::weakest($confidence, Confidence::Inferred), sprintf('%s declares no %s(); the model forwards it to its builder, %s', $ownerFqn, $name, $builder));
        }

        if ($this->magic?->isModel($ownerFqn) === true && $this->declaringBeyondTheIndex($ownerFqn, $name) === null
            && $this->declaringBeyondTheIndex($ownerFqn, '__call') !== null) {
            return CallTarget::at($ownerFqn.'::'.$name, Confidence::weakest($confidence, Confidence::Inferred), sprintf('%s declares no %s(); the model forwards it to its query builder', $ownerFqn, $name));
        }

        // A type no declaration anywhere carries, in a namespace the project's own code is in:
        // a missing `use`, or a template name read as a class. Code calling it would fail, so
        // the call is not a resolved edge to a class that does not exist.
        if ($this->existsNowhere($ownerFqn)) {
            return CallTarget::none(sprintf('%s: no such class is declared anywhere (a missing import?)', $ownerFqn));
        }

        // Only a class that was read to the end of its ancestry can prove a member is absent.
        // Everything else — a framework base class, an interface in a dependency, a name the
        // corpus never indexed — is a class whose API is larger than anything readable, and an
        // absence there says nothing at all.
        if (! $this->members->isComplete($ownerFqn) || $this->isEnumIntrinsic($ownerFqn, $name)) {
            return CallTarget::at($ownerFqn.'::'.$name, $confidence, $reason);
        }

        return CallTarget::none($this->whyNoMethod($ownerFqn, $name));
    }

    /**
     * The declared return type of a function the corpus itself declares.
     *
     * Only the corpus: a framework helper such as `redirect()` is not indexed, and there is no
     * file to read it from, so the answer there is that its return type was not read rather
     * than that it has none.
     */
    private function functionReturnType(Node\Name $name, Node\Expr\FuncCall $call): ResolvedType
    {
        $fqn = ltrim(Analyser::resolvedName($name), '\\');

        foreach ($this->graph->byFqn($fqn) as $record) {
            if ($record->kind === SymbolKind::FunctionDecl && $record->typeFqn !== null) {
                return ResolvedType::declared($record->typeFqn);
            }
        }

        // An unqualified call in a namespace falls back to the global function, as PHP does.
        $installed = $this->installed?->returnType($fqn, $call)
            ?? ($name->isQualified() || $name->isFullyQualified() ? null : $this->installed?->returnType($name->getLast(), $call));

        if ($installed instanceof ResolvedType) {
            return $installed;
        }

        return ResolvedType::unknown(sprintf('the return type of %s() was not read — no declaration of it is indexed', $name->getLast()));
    }

    /**
     * `from`, `tryFrom` and `cases` are given to every enum by the language itself, so they are
     * declared nowhere and are not missing.
     */
    private function isEnumIntrinsic(string $ownerFqn, string $name): bool
    {
        return in_array(strtolower($name), ['from', 'tryfrom', 'cases'], true)
            && $this->graph->classLike($ownerFqn)?->kind === SymbolKind::EnumType;
    }

    /**
     * The type a member yields when nothing declares it under the name that was written.
     */
    private function magically(string $ownerFqn, string $name, SymbolKind $kind): ?ResolvedType
    {
        $magic = $kind === SymbolKind::Method
            ? $this->magic?->method($ownerFqn, $name)
            : $this->magic?->property($ownerFqn, $name);

        if (! $magic instanceof MagicMember) {
            return null;
        }

        if ($magic->type !== null) {
            return ResolvedType::of($magic->type, $magic->confidence, $magic->reason);
        }

        if ($magic->declaration === null) {
            return null;
        }

        // A convention names a real declaration, and what that declaration returns is then an
        // ordinary lookup — carried at the strength of the weakest step that got us here.
        $owner = $magic->owner();
        $member = $magic->member();
        $declared = $owner === null || $member === null ? null : $this->member($owner, $member, SymbolKind::Method);

        return $declared?->typeFqn === null
            ? null
            : ResolvedType::of($declared->typeFqn, $magic->confidence, $magic->reason);
    }

    private function member(string $ownerFqn, string $name, SymbolKind $kind): ?SymbolRecord
    {
        return $this->members->declared($ownerFqn, $name, $kind);
    }

    /**
     * Binds anything a `@var Foo $x` annotation names, wherever it sits.
     *
     * These are written above arbitrary statements, not only above assignments — a `return`, a
     * `foreach`, a bare expression — so reading them only at assignments would miss the form
     * people actually use. Always inferred, never resolved: an annotation is a claim, not a
     * declaration.
     *
     * @param  list<Import>  $imports
     */
    public function observeAnnotation(Node $statement, Scope $scope, array $imports): void
    {
        $comment = $statement->getDocComment();

        if (! $comment instanceof Doc) {
            return;
        }

        $classes = $this->docblocks->read($comment->getText())['vars'];

        foreach ($classes as $variable => $type) {
            $scope->set($variable, ResolvedType::inferred($this->qualify($type, $scope->classFqn, $imports), '@var'));
        }

        foreach ($this->docblocks->elements($comment->getText())['vars'] as $variable => $elements) {
            $qualified = $this->qualifyAll($elements, $scope->classFqn, $imports);
            $scope->set($variable, isset($classes[$variable])
                ? ResolvedType::inferred($this->qualify($classes[$variable], $scope->classFqn, $imports), '@var')->withElements($qualified)
                : ResolvedType::arrayOf($qualified, '@var'));
        }
    }

    /**
     * @return array{params: array<string, string>, return: ?string, var: ?string, vars: array<string, string>}
     */
    private function documented(Node\FunctionLike $function): array
    {
        return $this->docblocks->read($function->getDocComment()?->getText() ?? '');
    }

    /**
     * Turns the short name a docblock used into a fully-qualified one, using the file's imports
     * — the same resolution the language itself would do, and the half a textual search cannot.
     *
     * @param  list<Import>  $imports
     */
    private function qualify(string $name, ?string $classFqn, array $imports): string
    {
        return Analyser::qualify($name, $classFqn, $imports);
    }

    /**
     * @param  array<string, string>  $names
     * @param  list<Import>  $imports
     * @return array<string, string>
     */
    private function qualifyAll(array $names, ?string $classFqn, array $imports): array
    {
        return array_map(fn (string $name): string => $this->qualify($name, $classFqn, $imports), $names);
    }

    private function variableName(Node $node): ?string
    {
        return $node instanceof Node\Expr\Variable && is_string($node->name) ? $node->name : null;
    }

    private static function classLikeTypeOf(?Node $type): ?string
    {
        if ($type instanceof Node\NullableType) {
            return self::classLikeTypeOf($type->type);
        }

        return $type instanceof Node\Name ? Analyser::resolvedName($type) : null;
    }

    /**
     * php-parser attaches a docblock to the statement, not to the assignment inside it, so an
     *
     * `\/** @var Foo *\/ = ...;` annotation is read from whichever node actually carries it.
     */
    private function docblockOf(Node $node): ?string
    {
        return $node->getDocComment()?->getText();
    }
}

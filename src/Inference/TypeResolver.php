<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Inference;

use Closure;
use PhpParser\Comment\Doc;
use PhpParser\Node;
use Vusys\Exocomp\Analysis\Analyser;
use Vusys\Exocomp\Analysis\Import;
use Vusys\Exocomp\Analysis\LiteralValue;
use Vusys\Exocomp\Framework\Laravel\Binding;
use Vusys\Exocomp\Index\SymbolGraph;
use Vusys\Exocomp\Index\SymbolRecord;
use Vusys\Exocomp\Project\ClassSource;
use Vusys\Exocomp\Project\InstalledFunctions;
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
    private DocBlockTypes $docblocks;

    private Members $members;

    private ?EloquentReturns $eloquent;

    /** @var \ArrayObject<string, ResolvedType|null> what {@see beyondTheIndex()} found, per class and method */
    private \ArrayObject $beyond;

    /**
     * @param  (Closure(string): ?Binding)|null  $boundTo  what the application's providers bind a
     *                                                     class name to, asked only when a
     *                                                     container lookup is actually met
     * @param  ?InstalledFunctions  $installed  the functions installed packages declare, asked
     *                                          when a function is not in the index
     * @param  ?ClassSource  $source  reads a class the index does not hold, so a method declared
     *                                in a framework parent or trait can be found and typed
     */
    public function __construct(private SymbolGraph $graph, private ?Magic $magic = null, private ?Closure $boundTo = null, private ?InstalledFunctions $installed = null, private ?ClassSource $source = null)
    {
        $this->docblocks = new DocBlockTypes;
        $this->members = new Members($graph);
        $this->eloquent = $magic instanceof Magic ? new EloquentReturns($graph, $this->members, $magic) : null;
        $this->beyond = new \ArrayObject;
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

        foreach ($function->getParams() as $param) {
            $name = $this->variableName($param->var);

            if ($name === null) {
                continue;
            }

            $declared = self::classLikeTypeOf($param->type);

            if ($declared !== null) {
                $scope->set($name, ResolvedType::declared($declared));

                continue;
            }

            $fromDoc = $documented['params'][$name] ?? null;

            if ($fromDoc !== null) {
                $scope->set($name, ResolvedType::inferred($this->qualify($fromDoc, $classFqn, $imports), '@param'));
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
            default => ResolvedType::unknown('expression kind carries no declared type'),
        };
    }

    /**
     * `$orders[0]`, on a collection of a known model, is one of its models.
     *
     * @param  list<Import>  $imports
     */
    private function elementType(Node\Expr\ArrayDimFetch $fetch, Scope $scope, array $imports): ResolvedType
    {
        $element = $this->eloquent?->elementOf($this->typeOf($fetch->var, $scope, $imports));

        return $element ?? ResolvedType::unknown('an element of something whose elements are not known');
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
     * Records the type of a `foreach` value variable, where the iterable is something whose
     * elements are known: a collection of a model, as Eloquent hands back.
     *
     * @param  list<Import>  $imports
     */
    public function observeForeach(Node\Stmt\Foreach_ $foreach, Scope $scope, array $imports): void
    {
        $name = $this->variableName($foreach->valueVar);

        if ($name === null || ! $this->eloquent instanceof EloquentReturns) {
            return;
        }

        $snapshot = clone $scope;
        $eloquent = $this->eloquent;

        $scope->setLazy($name, fn (): ?ResolvedType => $eloquent->elementOf($this->typeOf($foreach->expr, $snapshot, $imports)) ?? $snapshot->get($name));
    }

    /**
     * Records what an assignment establishes, so a later use of the variable resolves.
     *
     * @param  list<Import>  $imports
     */
    public function observeAssignment(Node\Expr\Assign $assign, Scope $scope, array $imports): void
    {
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

            if ($type->isKnown()) {
                return $type;
            }

            $fromDoc = $documented === null ? null : ($this->docblocks->read($documented)['var'] ?? null);

            return $fromDoc !== null
                ? ResolvedType::inferred($this->qualify($fromDoc, $snapshot->classFqn, $imports), '@var')
                : $snapshot->get($name);
        });
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
            return $member->typeFqn === null
                ? ResolvedType::unknown('property has no declared class type')
                : ResolvedType::declared($member->typeFqn);
        }

        return $this->eloquent?->fromRelationProperty((string) $owner->fqn, $name)
            ?? $this->magically((string) $owner->fqn, $name, SymbolKind::Property)
            ?? ResolvedType::unknown('property has no declared class type');
    }

    private function staticPropertyType(Node\Expr\StaticPropertyFetch $fetch, Scope $scope): ResolvedType
    {
        $owner = $this->className($fetch->class, $scope);

        if ($owner === null || ! $fetch->name instanceof Node\VarLikeIdentifier) {
            return ResolvedType::unknown('static property on an unknown class');
        }

        $member = $this->member($owner, $fetch->name->toString(), SymbolKind::Property);

        return $member?->typeFqn === null
            ? ResolvedType::unknown('static property has no declared class type')
            : ResolvedType::declared($member->typeFqn);
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
        // class knows nothing of the related model, and the method's body does.
        if ($owner->isKnown() && $type->isKnown() && str_starts_with((string) $type->fqn, 'Illuminate\\Database\\Eloquent\\Relations\\')) {
            $type = $this->eloquent?->fromRelation((string) $owner->fqn, $name) ?? $type;
        }

        // `app()->make(X::class)`, `$this->app->make(X::class)` and friends: the container's own
        // signature says `mixed`, so this only steps in where nothing declared said otherwise.
        if (! $type->isKnown() && in_array(strtolower($call->name->toString()), ['make', 'makewith'], true)) {
            return $this->containerLookup($call, $scope) ?? $type;
        }

        return $type;
    }

    private function staticReturnType(Node\Expr\StaticCall $call, Scope $scope): ResolvedType
    {
        $owner = $this->className($call->class, $scope);

        if ($owner === null || ! $call->name instanceof Node\Identifier) {
            return ResolvedType::unknown('static call on an unknown class');
        }

        $type = $this->returnTypeOf($owner, $call->name->toString());

        // Eloquent's convention outranks an annotation, never a declaration. The generated
        // `@method static Builder|Order query()` blocks name the bare framework class, which
        // knows nothing of the model, so read literally they ended every chain at `query()`.
        if (! $type->isKnown() || $type->confidence !== Confidence::Resolved) {
            $type = $this->eloquent?->fromModel($owner, $call->name->toString(), $call) ?? $type;
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
        $argument = $call->getArgs()[0]->value ?? null;
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

    private function returnTypeOf(string $ownerFqn, string $method): ResolvedType
    {
        $member = $this->member($ownerFqn, $method, SymbolKind::Method);

        if (! $member instanceof SymbolRecord) {
            return $this->magically($ownerFqn, $method, SymbolKind::Method)
                ?? $this->beyondTheIndex($ownerFqn, $method)
                ?? ResolvedType::unknown($this->whyNoMethod($ownerFqn, $method));
        }

        if ($member->typeFqn === null) {
            return ResolvedType::unknown('method declares no class-like return type');
        }

        // A fluent method declares `static` or `self`, which name a class only relative to the
        // call: `static` is the receiver's class, `self` the class that declares the method.
        // Taken literally, they named a class called "static" and every chain stopped there.
        return match (strtolower($member->typeFqn)) {
            'static' => ResolvedType::declared($ownerFqn),
            'self' => ResolvedType::declared($member->owner ?? $ownerFqn),
            default => ResolvedType::declared($member->typeFqn),
        };
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

        if ($this->beyond->offsetExists($key)) {
            return $this->beyond[$key];
        }

        // A model's framework methods are Eloquent's, which {@see EloquentReturns} answers by
        // convention; walking `Model` and its traits for each one cost more than it found.
        if ($this->magic?->isModel($ownerFqn) === true || $this->graph->members($ownerFqn)['unreachable'] === []) {
            return $this->beyond[$key] = null;
        }

        return $this->beyond[$key] = $this->declaredBeyondTheIndex($ownerFqn, $method);
    }

    private function declaredBeyondTheIndex(string $ownerFqn, string $method): ?ResolvedType
    {
        foreach ($this->source->withAncestors($ownerFqn) as $class) {
            $node = $class->node->getMethod($method);

            if (! $node instanceof Node\Stmt\ClassMethod) {
                continue;
            }

            $native = self::classLikeTypeOf($node->getReturnType());

            if ($native !== null) {
                return match (strtolower($native)) {
                    'static' => ResolvedType::declared($ownerFqn),
                    'self' => ResolvedType::declared($class->fqn),
                    default => ResolvedType::declared($native),
                };
            }

            $documented = $this->documented($node)['return'] ?? null;

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

    private function whyNoMethod(string $ownerFqn, string $method): string
    {
        // A class that declares `__call` answers to names it does not declare, so "declares no
        // X()" reads as a broken call when it is really a wall. Which of the two it is changes
        // what a reader should do about it, so the answer has to distinguish them.
        if ($this->member($ownerFqn, '__call', SymbolKind::Method) instanceof SymbolRecord) {
            return sprintf('%s declares no %s(), and declares __call(), so this resolves at runtime through that', $ownerFqn, $method);
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
    public function callTarget(string $ownerFqn, string $name, Confidence $confidence, string $reason): CallTarget
    {
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

        foreach ($this->docblocks->read($comment->getText())['vars'] as $variable => $type) {
            $scope->set($variable, ResolvedType::inferred($this->qualify($type, $scope->classFqn, $imports), '@var'));
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

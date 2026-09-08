<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Inference;

use PhpParser\Comment\Doc;
use PhpParser\Node;
use Vusys\Exocomp\Analysis\Analyser;
use Vusys\Exocomp\Analysis\Import;
use Vusys\Exocomp\Index\SymbolGraph;
use Vusys\Exocomp\Index\SymbolRecord;
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

    public function __construct(private SymbolGraph $graph)
    {
        $this->docblocks = new DocBlockTypes;
    }

    /**
     * Builds the scope for one function body: `$this`, the parameters, and the promoted
     * properties a constructor introduces.
     *
     * @param  list<Import>  $imports
     */
    public function scopeFor(?string $classFqn, ?string $parentFqn, ?Node\FunctionLike $function, array $imports): Scope
    {
        $scope = new Scope($classFqn, $parentFqn);

        if (! $function instanceof Node\FunctionLike) {
            return $scope;
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
            $expression instanceof Node\Expr\ClassConstFetch => $this->enumCaseType($expression, $scope),
            $expression instanceof Node\Expr\Assign => $this->typeOf($expression->expr, $scope, $imports),
            $expression instanceof Node\Expr\Clone_ => $this->typeOf($expression->expr, $scope, $imports),
            default => ResolvedType::unknown('expression kind carries no declared type'),
        };
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

        $type = $this->typeOf($assign->expr, $scope, $imports);
        $documented = $this->docblockOf($assign);

        if (! $type->isKnown() && $documented !== null) {
            $fromDoc = $this->docblocks->read($documented)['var'] ?? null;

            if ($fromDoc !== null) {
                $scope->set($name, ResolvedType::inferred($this->qualify($fromDoc, $scope->classFqn, $imports), '@var'));

                return;
            }
        }

        if ($type->isKnown()) {
            $scope->set($name, $type);
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

        $member = $this->member((string) $owner->fqn, $fetch->name->toString(), SymbolKind::Property);

        return $member?->typeFqn === null
            ? ResolvedType::unknown('property has no declared class type')
            : ResolvedType::declared($member->typeFqn);
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

        if (! $owner->isKnown()) {
            return ResolvedType::unknown($owner->reason);
        }

        return $this->returnTypeOf((string) $owner->fqn, $call->name->toString());
    }

    private function staticReturnType(Node\Expr\StaticCall $call, Scope $scope): ResolvedType
    {
        $owner = $this->className($call->class, $scope);

        if ($owner === null || ! $call->name instanceof Node\Identifier) {
            return ResolvedType::unknown('static call on an unknown class');
        }

        return $this->returnTypeOf($owner, $call->name->toString());
    }

    private function returnTypeOf(string $ownerFqn, string $method): ResolvedType
    {
        $member = $this->member($ownerFqn, $method, SymbolKind::Method);

        if (! $member instanceof SymbolRecord) {
            return ResolvedType::unknown('method not found on '.$ownerFqn);
        }

        return $member->typeFqn === null
            ? ResolvedType::unknown('method declares no class-like return type')
            : ResolvedType::declared($member->typeFqn);
    }

    /**
     * An enum case is an instance of its enum — the one place where a constant fetch names a
     * type rather than a value.
     */
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
    private function member(string $ownerFqn, string $name, SymbolKind $kind): ?SymbolRecord
    {
        foreach ($this->graph->members($ownerFqn)['members'] as $member) {
            $record = $member->record;

            if ($record->kind !== $kind) {
                continue;
            }

            $matches = $kind === SymbolKind::Method
                ? strcasecmp($record->name, $name) === 0
                : $record->name === $name;

            if ($matches) {
                return $record;
            }
        }

        return null;
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
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }

        if (in_array(strtolower($name), ['self', 'static', '$this'], true)) {
            return $classFqn ?? $name;
        }

        $head = explode('\\', $name)[0];

        foreach ($imports as $import) {
            if (strcasecmp($import->alias, $head) === 0) {
                return count(explode('\\', $name)) === 1
                    ? $import->fqn
                    : $import->fqn.substr($name, strlen($head));
            }
        }

        if ($classFqn !== null && ! str_contains($name, '\\')) {
            $namespace = substr($classFqn, 0, (int) strrpos($classFqn, '\\'));

            if ($namespace !== '') {
                return $namespace.'\\'.$name;
            }
        }

        return $name;
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

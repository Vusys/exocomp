<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Framework\Laravel;

use PhpParser\Node;
use PhpParser\NodeFinder;
use Vusys\Exocomp\Analysis\LiteralValue;
use Vusys\Exocomp\Index\SymbolRecord;
use Vusys\Exocomp\Inference\MagicMember;
use Vusys\Exocomp\Inference\MagicSource;
use Vusys\Exocomp\Inference\Members;
use Vusys\Exocomp\Project\AutoloadMap;
use Vusys\Exocomp\Project\ClassSource;
use Vusys\Exocomp\Symbol\Confidence;
use Vusys\Exocomp\Symbol\Identifier;
use Vusys\Exocomp\Symbol\SymbolKind;

/**
 * The members an Eloquent model answers to that are declared under another name.
 *
 * `Order::active()` is `Order::scopeActive()`. `$order->display_name` is
 * `getDisplayNameAttribute()`, or `displayName()` in the newer spelling. These are the largest
 * group of unresolvable calls in a Laravel application by a wide margin — 180 local scopes and
 * 304 accessors in one measured corpus — and none of them is really magic: the declaration is
 * usually in the same file as the call, under a name a total function of the one written.
 *
 * Because the transform is total and the target is found and checked, this is the one rung of
 * the ladder that can be {@see Confidence::Resolved}. It is only allowed to be on one condition:
 * that the installed framework is confirmed to still implement the convention. Hardcoding "the
 * `scope` prefix means a scope" would be right until the framework changed and would then be
 * wrong without saying so — the same mistake {@see FacadeResolver} was built to avoid by parsing
 * the alias table out of the framework in front of it rather than baking one in.
 *
 * What this deliberately does not do is give a relation its target class. `$order->customer`
 * resolves to `Order::customer()`, which is what a rename has to keep in step; that the relation
 * points at `Customer` is a fact about the `belongsTo()` argument, and `model` is where it is
 * read.
 */
final class EloquentConventions implements MagicSource
{
    /** The class whose presence, with these members, is what confirms the conventions. */
    public const string BUILDER = 'Illuminate\Database\Eloquent\Builder';

    public const string ATTRIBUTE = 'Illuminate\Database\Eloquent\Casts\Attribute';

    /** Where the framework keeps the attribute machinery — a trait, not the model. */
    public const string HAS_ATTRIBUTES = 'Illuminate\Database\Eloquent\Concerns\HasAttributes';

    private ?bool $scopesConfirmed = null;

    private ?bool $accessorsConfirmed = null;

    /** @var array<string, bool> */
    private array $models = [];

    /** @var array<string, array{string, string}|null> */
    private array $relations = [];

    public function __construct(
        private readonly ClassSource $source,
        private readonly Members $members,
        private readonly ?AutoloadMap $map = null,
    ) {}

    /**
     * The class a relation method on a model points at, and the relation factory that makes it
     * (`hasMany`, `belongsTo`, …), read from the method's body: `return $this->hasMany(X::class)`,
     * through any chain after it. Null when the method builds no relation the body names a class for.
     *
     * @return array{string, string}|null [target class, factory, lower-cased]
     */
    public function relationTarget(string $modelFqn, string $method): ?array
    {
        $key = Identifier::fold($modelFqn.'::'.$method);

        if (array_key_exists($key, $this->relations)) {
            return $this->relations[$key];
        }

        $declaration = $this->members->declared($modelFqn, $method, SymbolKind::Method);
        $owner = $declaration?->owner;
        $class = $owner === null ? null : $this->source->load($owner);
        $node = $class?->node->getMethod($method);

        if (! $node instanceof Node\Stmt\ClassMethod) {
            return $this->relations[$key] = null;
        }

        foreach (new NodeFinder()->findInstanceOf([$node], Node\Stmt\Return_::class) as $return) {
            $expression = $return->expr;

            while ($expression instanceof Node\Expr\MethodCall) {
                if ($expression->var instanceof Node\Expr\Variable && $expression->var->name === 'this'
                    && $expression->name instanceof Node\Identifier
                    && in_array($factory = strtolower($expression->name->toString()), ModelReader::RELATIONS, true)) {
                    $first = $expression->getArgs()[0]->value ?? null;
                    $target = $first === null ? null : LiteralValue::className($first, selfFqn: (string) $owner, staticFqn: $modelFqn);

                    return $this->relations[$key] = $target === null ? null : [$target, $factory];
                }

                $expression = $expression->var;
            }
        }

        return $this->relations[$key] = null;
    }

    public function method(string $ownerFqn, string $name): ?MagicMember
    {
        if (! $this->isModel($ownerFqn)) {
            return null;
        }

        $scope = $this->members->declared($ownerFqn, 'scope'.ucfirst($name), SymbolKind::Method);

        if (! $scope instanceof SymbolRecord) {
            return null;
        }

        [$confidence, $reason] = $this->confirmation(
            $this->scopesConfirmed ??= $this->frameworkDeclares('hasNamedScope'),
            sprintf('local scope, declared as %s()', $scope->name),
        );

        return MagicMember::spelled($ownerFqn.'::'.$scope->name, null, $confidence, $reason);
    }

    public function property(string $ownerFqn, string $name): ?MagicMember
    {
        if (! $this->isModel($ownerFqn)) {
            return null;
        }

        $accessor = $this->members->declared($ownerFqn, 'get'.$this->studly($name).'Attribute', SymbolKind::Method);

        if ($accessor instanceof SymbolRecord) {
            [$confidence, $reason] = $this->confirmation(
                $this->accessorsConfirmed ??= $this->frameworkDeclares('hasGetMutator'),
                sprintf('accessor, declared as %s()', $accessor->name),
            );

            return MagicMember::spelled($ownerFqn.'::'.$accessor->name, $accessor->typeFqn, $confidence, $reason);
        }

        // The newer spelling declares one method per attribute, named in camel case, and says
        // so by returning `Attribute`. Without that return type the name alone proves nothing —
        // every ordinary method on the model would match.
        $modern = $this->members->declared($ownerFqn, $this->camel($name), SymbolKind::Method);

        if ($modern instanceof SymbolRecord && $modern->typeFqn === self::ATTRIBUTE) {
            [$confidence, $reason] = $this->confirmation(
                $this->accessorsConfirmed ??= $this->frameworkDeclares('hasGetMutator'),
                sprintf('attribute, declared as %s(): Attribute', $modern->name),
            );

            return MagicMember::spelled($ownerFqn.'::'.$modern->name, null, $confidence, $reason);
        }

        // A relation read as an attribute. The declaration is what a rename has to keep in step
        // with; what the relation points at is a different question, and `model` answers it.
        $relation = $this->members->declared($ownerFqn, $name, SymbolKind::Method);

        if ($relation instanceof SymbolRecord && str_starts_with((string) $relation->typeFqn, 'Illuminate\Database\Eloquent\Relations\\')) {
            return MagicMember::spelled(
                $ownerFqn.'::'.$relation->name,
                null,
                Confidence::Inferred,
                sprintf('relation read as an attribute, declared as %s(): %s', $relation->name, $this->shortName((string) $relation->typeFqn)),
            );
        }

        return null;
    }

    /**
     * The call-site spellings of a declared member: `scopeActive()` is called as `active()`,
     * `getDisplayNameAttribute()` is read as `display_name`.
     *
     * This is what makes a rename of one of these safe to reason about. Without it, finding
     * every reference to `scopeActive` means searching for a string that appears exactly once
     * in the codebase — at the declaration — and reporting that as complete coverage.
     *
     * @return list<string>
     */
    public function spellings(string $ownerFqn, string $declaredName): array
    {
        if (! $this->isModel($ownerFqn)) {
            return [];
        }

        if (str_starts_with($declaredName, 'scope') && $declaredName !== 'scope') {
            return [lcfirst(substr($declaredName, 5))];
        }

        if (preg_match('/^(get|set)(.+)Attribute$/', $declaredName, $matches) === 1) {
            return [$this->snake($matches[2]), lcfirst($matches[2])];
        }

        // The newer spelling is an ordinary method name, so the name alone cannot say whether
        // it is an attribute. The return type is what declares the intent, and without it every
        // method on every model would claim a second spelling.
        $declared = $this->members->declared($ownerFqn, $declaredName, SymbolKind::Method);

        if ($declared instanceof SymbolRecord && $declared->typeFqn === self::ATTRIBUTE) {
            return [$this->snake($declaredName)];
        }

        return [];
    }

    /**
     * @return array{Confidence, string}
     */
    private function confirmation(bool $confirmed, string $reason): array
    {
        return $confirmed
            ? [Confidence::Resolved, $reason]
            : [Confidence::Inferred, $reason.', by convention — the installed framework could not be read to confirm it'];
    }

    public function isModel(string $fqn): bool
    {
        // Detected from the hierarchy rather than from framework detection: a class descending
        // from the model base class is one whether or not the checkout looks like an application.
        return $this->models[$fqn] ??= $this->source->descendsFrom($fqn, LaravelProfile::MODEL);
    }

    /**
     * Whether the framework in front of us still implements the convention being relied on.
     *
     * Read out of the installed framework's own files rather than assumed, for the reason
     * {@see FacadeResolver} parses the alias table instead of baking one in: a hardcoded
     * convention is right until the framework changes it and is then wrong without saying so.
     *
     * The three files are named rather than discovered. Walking the model's whole ancestry finds
     * the same answer and costs about a second and a half — Laravel's `Model` and `Builder` and
     * their twenty-odd traits, parsed in full, on every command that touches a model. Naming the
     * files is a hint about where to look, never the answer itself; when the method is not found
     * there the conventions degrade to `inferred` and say so, which is the safe direction.
     */
    private function frameworkDeclares(string $method): bool
    {
        if (! $this->map instanceof AutoloadMap) {
            return false;
        }

        foreach ([LaravelProfile::MODEL, self::BUILDER, self::HAS_ATTRIBUTES] as $fqn) {
            foreach ($this->map->candidates($fqn) as $path) {
                $source = is_file($path) ? file_get_contents($path) : false;

                if (is_string($source) && str_contains($source, 'function '.$method.'(')) {
                    return true;
                }
            }
        }

        return false;
    }

    private function studly(string $name): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $name)));
    }

    private function camel(string $name): string
    {
        return lcfirst($this->studly($name));
    }

    private function snake(string $name): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
    }

    private function shortName(string $fqn): string
    {
        $position = strrpos($fqn, '\\');

        return $position === false ? $fqn : substr($fqn, $position + 1);
    }
}

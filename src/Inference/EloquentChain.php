<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Inference;

use PhpParser\Node;
use Vusys\Exocomp\Analysis\Analyser;
use Vusys\Exocomp\Framework\Laravel\LaravelProfile;
use Vusys\Exocomp\Index\SymbolGraph;
use Vusys\Exocomp\Index\SymbolRecord;
use Vusys\Exocomp\Project\ClassSource;
use Vusys\Exocomp\Symbol\SymbolKind;

/**
 * Whether a call whose receiver is unknown is provably not a call on the target class, because
 * the receiver is something Eloquent's own API handed back from a model.
 *
 * `Contract::factory()->create()` is the commonest unresolved call in a Laravel application: the
 * receiver of `create()` is whatever `factory()` returns, and that is declared in `vendor/`. The
 * type is unknown, but the set of things it could be is not. Eloquent's API, called on a model,
 * returns builders, relations, factories, collections, models and scalars — framework classes,
 * or application classes that extend them. A class with no ancestor outside the indexed source
 * is none of those, so it cannot come back, and a same-named member on it is not what was called.
 *
 * Every condition below closes a way that argument fails, and any doubt leaves the call
 * unresolved rather than excluded, because a wrong exclusion is worse than an honest "unknown":
 *
 * - the target is a class or enum whose ancestry is read to the end — an interface or trait
 *   could be implemented or used by a model, and a vendor parent could be one;
 * - the chain is rooted at a model, by class name or by a receiver whose type is known;
 * - every link is a method call written by name, with no callback, `::class` or callable
 *   argument that could hand back an object of the caller's choosing;
 * - no link is a scope or a macro, and no application declaration of a link's name could be
 *   carried by what Eloquent returns unless its declared return type rules the target out;
 * - no link is one of the framework methods that return a callback's result or a stored value.
 */
final class EloquentChain
{
    /**
     * Framework methods whose result is a callback's return or a value read out of the model,
     * either of which can be an object of any class. Named, because nothing in the index can say
     * what a `vendor/` method returns.
     *
     * ponytail: a hand-kept list; read return types from the framework's own source if it
     * ever proves incomplete.
     *
     * @var list<string>
     */
    private const array OPAQUE = [
        'value', 'pluck', 'getattribute', 'getattributevalue', 'getrelation', 'getrelationvalue', 'getoriginal',
        'when', 'unless', 'whenempty', 'whennotempty', 'unlessempty', 'unlessnotempty', 'tap', 'pipe', 'pipeinto',
        'pipethrough', 'through', 'transform', 'map', 'mapinto', 'mapwithkeys', 'mapspread', 'maptogroups',
        'maptodictionary', 'flatmap', 'reduce', 'reducespread', 'reducewithkeys', 'sole', 'offsetget', '__get', '__call',
    ];

    private ?bool $targetIsSealed = null;

    /** @var array<string, bool> */
    private array $frameworkOnly = [];

    /** @var array<string, bool> */
    private array $models = [];

    private ?Members $members = null;

    public function __construct(
        private readonly string $target,
        private readonly SymbolGraph $graph,
        private readonly ClassSource $source,
        private readonly ?Magic $magic = null,
    ) {}

    /**
     * @param  callable(Node): ResolvedType  $typeOf  the scanner's own receiver resolution, so the
     *                                                root is typed exactly as everything else is
     */
    public function excludes(Node $receiver, callable $typeOf): bool
    {
        if (! $this->targetIsSealed()) {
            return false;
        }

        $links = [];

        while ($receiver instanceof Node\Expr\MethodCall || $receiver instanceof Node\Expr\NullsafeMethodCall || $receiver instanceof Node\Expr\StaticCall) {
            if (! $receiver->name instanceof Node\Identifier || $receiver->isFirstClassCallable() || ! $this->plainArguments(Analyser::arguments($receiver))) {
                return false;
            }

            $links[] = $receiver->name->toString();

            if ($receiver instanceof Node\Expr\StaticCall) {
                $receiver = $receiver->class;

                break;
            }

            $receiver = $receiver->var;
        }

        if ($links === []) {
            return false;
        }

        $root = $typeOf($receiver);

        if (! $root->isKnown() || ! $this->isModel((string) $root->fqn)) {
            return false;
        }

        return array_all($links, $this->isFrameworkOnly(...));
    }

    private function targetIsSealed(): bool
    {
        if ($this->targetIsSealed !== null) {
            return $this->targetIsSealed;
        }

        $record = $this->graph->classLike($this->target);

        return $this->targetIsSealed = $record instanceof SymbolRecord
            && in_array($record->kind, [SymbolKind::ClassType, SymbolKind::EnumType], true)
            && $this->members()->isComplete($this->target);
    }

    /**
     * @param  array<Node\Arg|Node\VariadicPlaceholder>  $arguments
     */
    private function plainArguments(array $arguments): bool
    {
        foreach ($arguments as $argument) {
            $value = $argument instanceof Node\Arg ? $argument->value : null;

            if ($value instanceof Node\Expr\Closure
                || $value instanceof Node\Expr\ArrowFunction
                || ($value instanceof Node\Expr\ClassConstFetch && $value->name instanceof Node\Identifier && strcasecmp($value->name->toString(), 'class') === 0)
                || ($value instanceof Node\Expr\Array_ && count($value->items) === 2 && $value->items[1]->value instanceof Node\Scalar\String_)
                || ($value instanceof Node\Scalar\String_ && str_contains($value->value, '\\'))) {
                return false;
            }
        }

        return true;
    }

    private function isModel(string $fqn): bool
    {
        return $this->models[strtolower($fqn)] ??= $this->source->descendsFrom($fqn, LaravelProfile::MODEL);
    }

    private function isFrameworkOnly(string $name): bool
    {
        $key = strtolower($name);

        return $this->frameworkOnly[$key] ??= ! in_array($key, self::OPAQUE, true)
            && $this->graph->byName('scope'.ucfirst($name), SymbolKind::Method) === []
            && ! ($this->magic?->registersMacro($name) ?? false)
            && array_all($this->graph->byName($name, SymbolKind::Method), $this->cannotBeReached(...));
    }

    /**
     * Whether an application declaration of a link's name is irrelevant to the chain.
     *
     * What Eloquent hands back is a framework class or an application class extending one, so a
     * declaration only matters where such a class could carry it: on a class whose ancestry
     * leaves the indexed source, or on a trait one could use. There it is still harmless if its
     * declared return type is unrelated to the target. An interface's declaration is carried by
     * whatever implements it, which is judged on its own.
     */
    private function cannotBeReached(SymbolRecord $method): bool
    {
        $owner = $method->owner === null ? null : $this->graph->classLike($method->owner);

        if ($owner?->kind === SymbolKind::InterfaceType) {
            return true;
        }

        if ($owner instanceof SymbolRecord && $owner->kind !== SymbolKind::TraitType && $this->members()->isComplete($owner->fqn)) {
            return true;
        }

        return $method->typeFqn !== null && ! $this->relatedToTarget($method->typeFqn);
    }

    private function relatedToTarget(string $fqn): bool
    {
        return strcasecmp($fqn, $this->target) === 0
            || $this->hasAncestor($fqn, $this->target)
            || $this->hasAncestor($this->target, $fqn);
    }

    private function hasAncestor(string $child, string $ancestor): bool
    {
        return array_any(array_keys($this->graph->ancestorsOf($child)), static fn (string $fqn): bool => strcasecmp($fqn, $ancestor) === 0);
    }

    private function members(): Members
    {
        return $this->members ??= new Members($this->graph);
    }

    /**
     * The sentence the header's count is explained by.
     */
    public static function explanation(int $count, string $target): string
    {
        return sprintf(
            '%d call%s excluded: each is on a value Eloquent returned from a model, through framework methods only, and %s has no ancestor outside the indexed source, so Eloquent cannot return one',
            $count,
            $count === 1 ? '' : 's',
            $target,
        );
    }
}

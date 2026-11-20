<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Framework\Laravel;

use PhpParser\Node;
use PhpParser\NodeFinder;
use Vusys\Exocomp\Analysis\Analyser;
use Vusys\Exocomp\Analysis\LiteralValue;
use Vusys\Exocomp\Source\ParseFailure;
use Vusys\Exocomp\Source\SourceFile;
use Vusys\Exocomp\Symbol\Confidence;
use Vusys\Exocomp\Symbol\Identifier;

/**
 * The observers registered for one model, read from the three places Laravel takes them:
 * `#[ObservedBy(…)]` on the model, `Model::observe(…)` (usually in a provider's `boot()`), and
 * an event provider's `$observers` array.
 *
 * An observer is registered for that exact class. The framework names the event after
 * `static::class`, so one registered on a parent model does not hear its subclasses, and none is
 * inherited here either.
 */
final class ObserverReader
{
    private const string OBSERVED_BY = 'Illuminate\Database\Eloquent\Attributes\ObservedBy';

    /**
     * The model events each write fires. The first list is what it always fires; the second,
     * what it fires only for some models, such as `save()` creating a new one or updating an
     * existing one.
     *
     * @var array<string, array{list<string>, list<string>}>
     */
    public const array EVENTS = [
        'save' => [['saving', 'saved'], ['creating', 'created', 'updating', 'updated']],
        'push' => [['saving', 'saved'], ['creating', 'created', 'updating', 'updated']],
        'touch' => [[], ['saving', 'saved', 'updating', 'updated']],
        'create' => [['saving', 'saved', 'creating', 'created'], []],
        'forcecreate' => [['saving', 'saved', 'creating', 'created'], []],
        'firstorcreate' => [[], ['saving', 'saved', 'creating', 'created']],
        'updateorcreate' => [[], ['saving', 'saved', 'creating', 'created', 'updating', 'updated']],
        'update' => [[], ['saving', 'saved', 'updating', 'updated']],
        'increment' => [[], ['updating', 'updated']],
        'decrement' => [[], ['updating', 'updated']],
        'delete' => [['deleting', 'deleted'], []],
        'forcedelete' => [['forceDeleting', 'forceDeleted', 'deleting', 'deleted'], []],
        'restore' => [['restoring', 'restored'], ['saving', 'saved', 'updating', 'updated']],
    ];

    /** @var list<DispatchEdge> */
    private array $observers = [];

    public function __construct(
        private readonly Analyser $analyser = new Analyser,
    ) {}

    /**
     * @param  list<string>  $paths  display paths of every file that mentions the model
     * @param  callable(string): string  $absolute
     * @return list<DispatchEdge>
     */
    public function read(string $model, array $paths, callable $absolute): array
    {
        foreach ($paths as $path) {
            $file = $absolute($path);

            if (! is_file($file)) {
                continue;
            }

            try {
                [, $statements] = $this->analyser->analyseWithAst(SourceFile::fromPath($file, $path));
            } catch (ParseFailure) {
                continue;
            }

            $this->attributes($statements, $model, $path);
            $this->observeCalls($statements, $model, $path);
            $this->observersProperty($statements, $model, $path);
        }

        $unique = [];

        foreach ($this->observers as $edge) {
            $unique[Identifier::fold((string) $edge->counterpart)] ??= $edge;
        }

        return array_values($unique);
    }

    /**
     * @param  array<Node>  $statements
     */
    private function attributes(array $statements, string $model, string $path): void
    {
        foreach (new NodeFinder()->findInstanceOf($statements, Node\Stmt\Class_::class) as $class) {
            if (Identifier::fold($class->namespacedName?->toString() ?? '') !== Identifier::fold($model)) {
                continue;
            }

            foreach ($class->attrGroups as $group) {
                foreach ($group->attrs as $attribute) {
                    if (Identifier::fold(Analyser::resolvedName($attribute->name)) !== Identifier::fold(self::OBSERVED_BY)) {
                        continue;
                    }

                    foreach ($attribute->args as $argument) {
                        $this->add($model, $argument->value, $path, $attribute->getStartLine(), 'registered by #[ObservedBy] on the model');
                    }
                }
            }
        }
    }

    /**
     * @param  array<Node>  $statements
     */
    private function observeCalls(array $statements, string $model, string $path): void
    {
        foreach (new NodeFinder()->findInstanceOf($statements, Node\Expr\StaticCall::class) as $call) {
            if (! $call->class instanceof Node\Name || ! $call->name instanceof Node\Identifier
                || strtolower($call->name->toString()) !== 'observe'
                || Identifier::fold(Analyser::resolvedName($call->class)) !== Identifier::fold($model)) {
                continue;
            }

            $argument = $call->args[0] ?? null;

            if ($argument instanceof Node\Arg) {
                $this->add($model, $argument->value, $path, $call->getStartLine(), 'registered by '.$this->short($model).'::observe()');
            }
        }
    }

    /**
     * @param  array<Node>  $statements
     */
    private function observersProperty(array $statements, string $model, string $path): void
    {
        foreach (new NodeFinder()->findInstanceOf($statements, Node\Stmt\Property::class) as $property) {
            foreach ($property->props as $item) {
                if ($item->name->toString() !== 'observers' || ! $item->default instanceof Node\Expr\Array_) {
                    continue;
                }

                foreach ($item->default->items as $entry) {
                    if ($entry->key instanceof Node\Expr && Identifier::fold(LiteralValue::className($entry->key) ?? '') === Identifier::fold($model)) {
                        $this->add($model, $entry->value, $path, $entry->getStartLine(), 'registered in $observers');
                    }
                }
            }
        }
    }

    /**
     * One observer class, or an array of them, written as `X::class`.
     */
    private function add(string $model, Node\Expr $value, string $path, int $line, string $how): void
    {
        $classes = $value instanceof Node\Expr\Array_
            ? array_map(static fn (Node\ArrayItem $item): Node\Expr => $item->value, $value->items)
            : [$value];

        foreach ($classes as $class) {
            $observer = LiteralValue::className($class);

            if ($observer !== null) {
                $this->observers[] = new DispatchEdge('observer', $model, $observer, $path, $line, Confidence::Resolved, $how);
            }
        }
    }

    private function short(string $fqn): string
    {
        $at = strrpos($fqn, '\\');

        return $at === false ? $fqn : substr($fqn, $at + 1);
    }
}

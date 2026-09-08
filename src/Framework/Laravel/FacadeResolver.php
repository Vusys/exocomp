<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Framework\Laravel;

use PhpParser\Node;
use PhpParser\NodeFinder;
use Vusys\Exocomp\Analysis\LiteralValue;
use Vusys\Exocomp\Project\ClassSource;
use Vusys\Exocomp\Project\LoadedClass;
use Vusys\Exocomp\Symbol\Confidence;

/**
 * `Storage::disk()` to the class that actually runs.
 *
 * 678 measured searches were facade questions, and another 205 were reading `vendor/` framework
 * internals by hand to answer exactly this. The chain is three hops and none of them is
 * visible from the call site: the facade returns an accessor string from a protected method,
 * the string is a container alias, and the alias points at a concrete class registered in the
 * framework's own bootstrap.
 *
 * All three hops are read from source. The last one — the core alias table — lives in
 * `Illuminate\Foundation\Application`, which is in `vendor/`; that file is located through
 * Composer's autoload map and read on its own, so the answer does not require indexing sixty
 * thousand vendor files to get at one array.
 *
 * Nothing is hardcoded. A table of "Storage means FilesystemManager" baked into this tool would
 * be right until the framework changed and would then be wrong without saying so.
 */
final class FacadeResolver
{
    private const string APPLICATION = 'Illuminate\Foundation\Application';

    private const string FACADE_NAMESPACE = 'Illuminate\Support\Facades\\';

    /** @var ?array<string, string> */
    private ?array $coreAliases = null;

    /** @var list<string> */
    private array $notes = [];

    public function __construct(
        private readonly ClassSource $source,
        private readonly ContainerReader $container,
    ) {}

    /**
     * The facade class a name refers to: a fully-qualified one as written, a short one tried
     * against the framework's facade namespace first and then against whatever the corpus has.
     */
    public function facadeClass(string $name, ?string $indexed): ?LoadedClass
    {
        $candidates = str_contains($name, '\\')
            ? [$name]
            : [self::FACADE_NAMESPACE.$name, $indexed];

        foreach ($candidates as $candidate) {
            if ($candidate === null) {
                continue;
            }

            $class = $this->source->load($candidate);

            if ($class instanceof LoadedClass && $this->isFacade($class)) {
                return $class;
            }
        }

        return null;
    }

    /**
     * What `getFacadeAccessor()` returns: a class name, or the container key that stands for one.
     *
     * @return ?array{string, bool} the accessor, and whether it is already a class name
     */
    public function accessor(LoadedClass $facade): ?array
    {
        $method = $facade->node->getMethod('getFacadeAccessor');

        if (! $method instanceof Node\Stmt\ClassMethod) {
            return null;
        }

        foreach (new NodeFinder()->findInstanceOf([$method], Node\Stmt\Return_::class) as $return) {
            if (! $return->expr instanceof Node\Expr) {
                continue;
            }

            $className = LiteralValue::className($return->expr);

            if ($className !== null) {
                return [$className, true];
            }

            $value = LiteralValue::scalar($return->expr);

            if (is_string($value)) {
                return [$value, false];
            }
        }

        return null;
    }

    /**
     * The concrete class behind an accessor string, and how it was established.
     *
     * @return array{?string, Confidence, string}
     */
    public function concrete(string $accessor, bool $isClassName): array
    {
        if ($isClassName) {
            return [$accessor, Confidence::Resolved, 'the facade names its class directly'];
        }

        $binding = $this->container->concreteFor($accessor);

        if ($binding instanceof Binding && $binding->concrete !== null) {
            return [$binding->concrete, Confidence::Inferred, sprintf('bound to "%s" in %s', $accessor, $binding->registeredBy ?? 'a service provider')];
        }

        $core = $this->coreAliases()[$accessor] ?? null;

        if ($core !== null) {
            return [$core, Confidence::Inferred, sprintf('"%s" is a core container alias, read from %s', $accessor, self::APPLICATION)];
        }

        return [
            null,
            Confidence::Textual,
            sprintf('the facade resolves the container key "%s", which nothing in this project or in the framework\'s own alias table binds', $accessor),
        ];
    }

    /**
     * @return list<string>
     */
    public function notes(): array
    {
        return $this->notes;
    }

    private function isFacade(LoadedClass $class): bool
    {
        foreach ($class->analysis->declarations as $declaration) {
            if (strcasecmp($declaration->fqn, $class->fqn) !== 0) {
                continue;
            }

            foreach ($declaration->extends as $parent) {
                if (strcasecmp($parent, LaravelProfile::FACADE) === 0) {
                    return true;
                }
            }
        }

        // A facade one level removed from the base still is one; its own parent has to be asked.
        return $class->node instanceof Node\Stmt\Class_
            && $class->node->extends instanceof Node\Name
            && $this->extendsFacade($class->node->extends->toString(), 0);
    }

    private function extendsFacade(string $fqn, int $depth): bool
    {
        if ($depth > 8 || strcasecmp($fqn, LaravelProfile::FACADE) === 0) {
            return strcasecmp($fqn, LaravelProfile::FACADE) === 0;
        }

        $parent = $this->source->load($fqn);

        if (! $parent instanceof LoadedClass || ! $parent->node instanceof Node\Stmt\Class_ || ! $parent->node->extends instanceof Node\Name) {
            return false;
        }

        return $this->extendsFacade($parent->node->extends->toString(), $depth + 1);
    }

    /**
     * The framework's own alias table, parsed out of `registerCoreContainerAliases()`.
     *
     * @return array<string, string>
     */
    private function coreAliases(): array
    {
        if ($this->coreAliases !== null) {
            return $this->coreAliases;
        }

        $this->coreAliases = [];
        $application = $this->source->load(self::APPLICATION);

        if (! $application instanceof LoadedClass) {
            $this->notes[] = self::APPLICATION.' could not be read, so core container aliases are unavailable';

            return $this->coreAliases;
        }

        $method = $application->node->getMethod('registerCoreContainerAliases');

        if (! $method instanceof Node\Stmt\ClassMethod) {
            return $this->coreAliases;
        }

        foreach (new NodeFinder()->findInstanceOf([$method], Node\Expr\Array_::class) as $array) {
            foreach ($array->items as $item) {
                if (! $item->key instanceof Node\Expr || ! $item->value instanceof Node\Expr\Array_) {
                    continue;
                }

                $alias = LiteralValue::scalar($item->key);
                $first = $item->value->items[0]->value ?? null;
                $class = $first === null ? null : LiteralValue::className($first);

                // The list is ordered concrete-first, then the contracts it satisfies. The
                // concrete class is the one someone chasing `Storage::disk()` is after.
                if (is_string($alias) && $class !== null) {
                    $this->coreAliases[$alias] = $class;
                }
            }
        }

        return $this->coreAliases;
    }
}

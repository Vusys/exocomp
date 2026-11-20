<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Framework\Laravel;

use PhpParser\Node;
use PhpParser\NodeFinder;
use Vusys\Exocomp\Analysis\Analyser;
use Vusys\Exocomp\Analysis\LiteralValue;
use Vusys\Exocomp\Project\Project;
use Vusys\Exocomp\Source\ParseFailure;
use Vusys\Exocomp\Source\SourceFile;
use Vusys\Exocomp\Symbol\Confidence;

/**
 * The middleware a route runs before its controller, from what the application declares: an
 * HTTP kernel's `$middleware`, `$middlewareGroups` and `$middlewareAliases` (or `$routeMiddleware`),
 * and the calls in `bootstrap/app.php`'s `withMiddleware()` — `alias()`, `append()`,
 * `prepend()`, `group()`, `appendToGroup()`, `prependToGroup()`, `web()` and `api()`.
 *
 * Only what the application writes is read. The framework's own defaults (`auth`, `throttle`,
 * the classes in the stock `web` group) live in the framework, so a name only they define is
 * reported as not resolved rather than guessed.
 */
final class MiddlewareReader
{
    /** @var array<string, string> alias => class */
    private array $aliases = [];

    /** @var array<string, list<string>> group => entries */
    private array $groups = [];

    /** @var list<string> */
    private array $global = [];

    /** @var list<string> files that declared any of it */
    private array $sources = [];

    public function __construct(
        private readonly Analyser $analyser = new Analyser,
    ) {}

    public static function forProject(Project $project): self
    {
        $reader = new self;

        foreach (['app/Http/Kernel.php', 'bootstrap/app.php'] as $relative) {
            $reader->readFile($project->path($relative), $relative);
        }

        return $reader;
    }

    /**
     * The middleware classes a route runs, each with how it was found and how sure that is,
     * and the names that could not be resolved to a class.
     *
     * @param  list<string>  $routeMiddleware  as written on the route and its enclosing groups
     * @param  string  $routeFile  display path of the file the route is declared in
     * @return array{list<array{string, Confidence, string}>, list<string>}
     */
    public function forRoute(array $routeMiddleware, string $routeFile): array
    {
        $found = [];
        $unresolved = [];
        $implicit = match (basename($routeFile)) {
            'web.php' => 'web',
            'api.php' => 'api',
            default => null,
        };

        foreach ($this->global as $entry) {
            $this->expand($entry, Confidence::Resolved, 'global middleware', $found, $unresolved);
        }

        if ($implicit !== null && isset($this->groups[$implicit])) {
            $this->expand($implicit, Confidence::Inferred, sprintf('the "%s" group, which Laravel applies to %s by convention', $implicit, basename($routeFile)), $found, $unresolved);
        }

        foreach ($routeMiddleware as $entry) {
            $this->expand($entry, Confidence::Resolved, 'on the route', $found, $unresolved);
        }

        return [array_values($found), array_values(array_unique($unresolved))];
    }

    /**
     * Where the declarations were read, for a reason to cite.
     */
    public function source(): string
    {
        return $this->sources === [] ? 'no middleware declarations' : implode(' and ', array_unique($this->sources));
    }

    /**
     * @param  array<string, array{string, Confidence, string}>  $found
     * @param  list<string>  $unresolved
     * @param  array<string, true>  $seen  groups already being expanded, against a cycle
     */
    private function expand(string $entry, Confidence $confidence, string $how, array &$found, array &$unresolved, array $seen = []): void
    {
        $name = explode(':', $entry, 2)[0];

        if (isset($this->groups[$name]) && ! isset($seen[$name])) {
            foreach ($this->groups[$name] as $member) {
                $this->expand($member, $confidence, $how, $found, $unresolved, [...$seen, $name => true]);
            }

            return;
        }

        $class = $this->aliases[$name] ?? (str_contains($name, '\\') ? ltrim($name, '\\') : null);

        if ($class === null) {
            $unresolved[] = $name;

            return;
        }

        $found[strtolower($class)] ??= [$class, $confidence, $how];
    }

    private function readFile(string $file, string $relative): void
    {
        if (! is_file($file)) {
            return;
        }

        try {
            [, $statements] = $this->analyser->analyseWithAst(SourceFile::fromPath($file, $relative));
        } catch (ParseFailure) {
            return;
        }

        $before = [count($this->aliases), count($this->groups), count($this->global)];

        $this->kernelProperties($statements);
        $this->bootstrapCalls($statements);

        if ($before !== [count($this->aliases), count($this->groups), count($this->global)]) {
            $this->sources[] = $relative;
        }
    }

    /**
     * @param  array<Node>  $statements
     */
    private function kernelProperties(array $statements): void
    {
        foreach (new NodeFinder()->findInstanceOf($statements, Node\Stmt\Property::class) as $property) {
            foreach ($property->props as $item) {
                if (! $item->default instanceof Node\Expr\Array_) {
                    continue;
                }

                match ($item->name->toString()) {
                    'middleware' => $this->global = [...$this->global, ...$this->names($item->default)],
                    'middlewareGroups' => $this->addGroups($item->default),
                    'middlewareAliases', 'routeMiddleware' => $this->addAliases($item->default),
                    default => null,
                };
            }
        }
    }

    /**
     * The `$middleware->…()` calls inside `withMiddleware()`.
     *
     * @param  array<Node>  $statements
     */
    private function bootstrapCalls(array $statements): void
    {
        foreach (new NodeFinder()->findInstanceOf($statements, Node\Expr\MethodCall::class) as $call) {
            if (! $call->name instanceof Node\Identifier || ! $call->var instanceof Node\Expr\Variable || $call->var->name !== 'middleware') {
                continue;
            }

            $arguments = $this->arguments($call);

            match (strtolower($call->name->toString())) {
                'alias' => isset($arguments[0]) && $arguments[0] instanceof Node\Expr\Array_ ? $this->addAliases($arguments[0]) : null,
                'append', 'prepend' => $this->global = [...$this->global, ...$this->names($arguments[0] ?? null)],
                'group' => $this->addToGroup($arguments[0] ?? null, $arguments[1] ?? null),
                'appendtogroup', 'prependtogroup' => $this->addToGroup($arguments[0] ?? null, $arguments[1] ?? null),
                'web', 'api' => $this->addToNamedGroup(strtolower($call->name->toString()), [$arguments['append'] ?? null, $arguments['prepend'] ?? null]),
                default => null,
            };
        }
    }

    /**
     * Positional arguments by index and named ones by name.
     *
     * @return array<int|string, Node\Expr>
     */
    private function arguments(Node\Expr\MethodCall $call): array
    {
        $arguments = [];

        foreach ($call->args as $index => $argument) {
            if ($argument instanceof Node\Arg) {
                $arguments[$argument->name?->toString() ?? $index] = $argument->value;
            }
        }

        return $arguments;
    }

    private function addAliases(Node\Expr\Array_ $array): void
    {
        foreach ($array->items as $item) {
            $alias = $item->key instanceof Node\Expr ? LiteralValue::scalar($item->key) : null;
            $class = LiteralValue::className($item->value);

            if (is_string($alias) && $class !== null) {
                $this->aliases[$alias] = $class;
            }
        }
    }

    private function addGroups(Node\Expr\Array_ $array): void
    {
        foreach ($array->items as $item) {
            $group = $item->key instanceof Node\Expr ? LiteralValue::scalar($item->key) : null;

            if (is_string($group)) {
                $this->groups[$group] = [...$this->groups[$group] ?? [], ...$this->names($item->value)];
            }
        }
    }

    private function addToGroup(?Node\Expr $group, ?Node\Expr $members): void
    {
        $name = $group instanceof Node\Expr ? LiteralValue::scalar($group) : null;

        if (is_string($name)) {
            $this->groups[$name] = [...$this->groups[$name] ?? [], ...$this->names($members)];
        }
    }

    /**
     * @param  list<?Node\Expr>  $lists
     */
    private function addToNamedGroup(string $group, array $lists): void
    {
        foreach ($lists as $list) {
            $this->groups[$group] = [...$this->groups[$group] ?? [], ...$this->names($list)];
        }
    }

    /**
     * Middleware written as `X::class`, a string, or an array of either.
     *
     * @return list<string>
     */
    private function names(?Node\Expr $value): array
    {
        if (! $value instanceof Node\Expr) {
            return [];
        }

        $items = $value instanceof Node\Expr\Array_ ? array_map(static fn (Node\ArrayItem $item): Node\Expr => $item->value, $value->items) : [$value];
        $names = [];

        foreach ($items as $item) {
            $name = LiteralValue::className($item) ?? LiteralValue::scalar($item);

            if (is_string($name)) {
                $names[] = $name;
            }
        }

        return $names;
    }
}

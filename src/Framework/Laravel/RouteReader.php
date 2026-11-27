<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Framework\Laravel;

use PhpParser\Node;
use PhpParser\Node\Expr;
use Vusys\Exocomp\Analysis\Analyser;
use Vusys\Exocomp\Analysis\LiteralValue;
use Vusys\Exocomp\Source\ParseFailure;
use Vusys\Exocomp\Source\SourceFile;
use Vusys\Exocomp\Symbol\Confidence;

/**
 * Reads the route files into route-to-controller-to-action edges.
 *
 * Groups are the reason this is worth a tool. A URI is the concatenation of every enclosing
 * prefix, so `grep "'orders'" routes/` finds the line that declares the route and tells you
 * nothing about the path it actually answers on — and the prefixes are two files and four
 * nesting levels away. The same goes for names, middleware and the controller a
 * `Route::controller()` group applies to everything inside it.
 *
 * A closure route has no controller to point at, and a route registered by a loop or a macro
 * cannot be read at all. Both are reported rather than dropped: the route table is the entry
 * point for reachability, and one silently missing endpoint is a hole in every answer built on
 * top of it.
 */
final class RouteReader
{
    /** @var list<string> */
    private const array VERBS = ['get', 'post', 'put', 'patch', 'delete', 'options', 'any'];

    /**
     * The seven routes `Route::resource()` registers, and the five `apiResource()` does. The
     * framework generates these; nothing in the source lists them, so a reader that reported
     * only the one `resource()` line would be hiding six endpoints.
     *
     * @var array<string, array{string, string}>
     */
    private const array RESOURCE = [
        'index' => ['GET', ''],
        'create' => ['GET', '/create'],
        'store' => ['POST', ''],
        'show' => ['GET', '/{id}'],
        'edit' => ['GET', '/{id}/edit'],
        'update' => ['PUT|PATCH', '/{id}'],
        'destroy' => ['DELETE', '/{id}'],
    ];

    /** @var list<string> */
    private const array API_ONLY = ['index', 'store', 'show', 'update', 'destroy'];

    /** @var list<RouteDefinition> */
    private array $routes = [];

    /** @var list<RouteDefinition> */
    private array $declined = [];

    /** @var list<string> */
    private array $notes = [];

    private int $filesRead = 0;

    /**
     * @param  ?string  $rootNamespace  what a string-named controller is relative to, read off
     *                                  the application's route provider by {@see RouteNamespace}
     */
    public function __construct(
        private readonly ?string $rootNamespace = null,
        private readonly Analyser $analyser = new Analyser,
    ) {}

    /**
     * @param  callable(string): string  $displayPath
     */
    public function readDirectory(string $directory, callable $displayPath): self
    {
        if (! is_dir($directory)) {
            $this->notes[] = sprintf('%s is not there, so no routes could be read', $directory);

            return $this;
        }

        $files = glob(rtrim($directory, '/').'/*.php');

        if ($files === false) {
            return $this;
        }

        sort($files);

        foreach ($files as $absolute) {
            $this->readFile($absolute, $displayPath($absolute));
        }

        return $this;
    }

    /**
     * @return list<RouteDefinition>
     */
    public function routes(): array
    {
        return $this->routes;
    }

    /**
     * @return list<RouteDefinition>
     */
    public function declined(): array
    {
        return $this->declined;
    }

    public function filesRead(): int
    {
        return $this->filesRead;
    }

    /**
     * @return list<string>
     */
    public function notes(): array
    {
        return $this->notes;
    }

    private function readFile(string $absolute, string $path): void
    {
        try {
            [, $statements] = $this->analyser->analyseWithAst(SourceFile::fromPath($absolute, $path));
        } catch (ParseFailure $failure) {
            $this->notes[] = sprintf('%s:%d could not be parsed, so its routes are missing: %s', $path, $failure->startLine, $failure->getMessage());

            return;
        }

        $this->filesRead++;
        $this->walk($statements, new RouteContext(namespace: $this->rootNamespace ?? ''), $path);
    }

    /**
     * @param  array<int, Node>  $statements
     */
    private function walk(array $statements, RouteContext $context, string $path): void
    {
        foreach ($statements as $statement) {
            if ($statement instanceof Node\Stmt\Expression) {
                $this->registration($statement->expr, $context, $path);

                continue;
            }

            // Routes inside an `if` or a `foreach` are still routes. The condition cannot be
            // evaluated here, so what is inside is read and the uncertainty is not claimed away.
            foreach ($statement->getSubNodeNames() as $name) {
                $value = $statement->{$name};
                $children = array_values(array_filter(is_array($value) ? $value : [$value], static fn (mixed $child): bool => $child instanceof Node));

                if ($children !== []) {
                    $this->walk($children, $context, $path);
                }
            }
        }
    }

    private function registration(Expr $expression, RouteContext $context, string $path): void
    {
        $links = $this->chain($expression);

        if ($links === []) {
            return;
        }

        [$base, $modifiers] = [$links[0], array_slice($links, 1)];
        $method = strtolower($base['name']);
        $all = [$base, ...$modifiers];

        foreach ($all as $index => $link) {
            if (strtolower($link['name']) !== 'group') {
                continue;
            }

            $this->group($all, $index, $context, $path);

            return;
        }

        $name = $this->modifierString($modifiers, 'name') ?? '';
        $middleware = $this->modifierList($modifiers, 'middleware');

        if (in_array($method, self::VERBS, true)) {
            $this->verb($base, $method, $context, $name, $middleware, $path);

            return;
        }

        if ($method === 'match') {
            $this->verb($base, 'match', $context, $name, $middleware, $path, offset: 1);

            return;
        }

        if ($method === 'resource' || $method === 'apiresource') {
            $this->resource($base, $modifiers, $method === 'apiresource', $context, $middleware, $path);
        }
    }

    /**
     * @param  list<array{name: string, args: array<int, Node\Arg>, line: int}>  $links
     */
    private function group(array $links, int $groupIndex, RouteContext $context, string $path): void
    {
        $prefix = '';
        $name = '';
        $namespace = '';
        $middleware = [];
        $controller = null;
        $body = null;

        foreach ($links as $index => $link) {
            $method = strtolower($link['name']);

            if ($index === $groupIndex) {
                foreach ($link['args'] as $argument) {
                    if ($argument->value instanceof Expr\Closure || $argument->value instanceof Expr\ArrowFunction) {
                        $body = $argument->value;

                        continue;
                    }

                    // `Route::group(['prefix' => 'api', 'as' => 'api.'], …)` — the array form.
                    foreach (LiteralValue::pairs($argument->value) as $pair) {
                        match ($pair['key']) {
                            'prefix' => $prefix = (string) $pair['value'],
                            'as' => $name = (string) $pair['value'],
                            'namespace' => $namespace = (string) $pair['value'],
                            default => null,
                        };
                    }

                    $middleware = [...$middleware, ...$this->optionList($argument->value, 'middleware')];
                }

                continue;
            }

            match ($method) {
                'prefix' => $prefix = $this->firstString($link['args']) ?? $prefix,
                'name', 'as' => $name = $this->firstString($link['args']) ?? $name,
                'controller' => $controller = $this->firstClass($link['args']),
                'namespace' => $namespace = $this->firstString($link['args']) ?? $namespace,
                'middleware' => $middleware = [...$middleware, ...$this->stringList($link['args'])],
                default => null,
            };
        }

        if ($body === null) {
            return;
        }

        $inner = $context->with($prefix, $name, $middleware, $controller, $namespace);

        foreach ($body->getStmts() as $statement) {
            $this->walk([$statement], $inner, $path);
        }
    }

    /**
     * @param  array{name: string, args: array<int, Node\Arg>, line: int}  $base
     * @param  list<string>  $middleware
     */
    private function verb(array $base, string $method, RouteContext $context, string $name, array $middleware, string $path, int $offset = 0): void
    {
        $uri = $this->firstString($base['args'], $offset);

        if ($uri === null) {
            $this->declined[] = new RouteDefinition(strtoupper($method), '?', null, null, null, $middleware, $path, $base['line'], Confidence::Textual, 'the URI is not a literal');

            return;
        }

        $action = $base['args'][$offset + 1]->value ?? null;

        // The options-array form carries the name and the middleware alongside the action, so
        // reading only `uses` loses the name a route is most often looked up by.
        if ($action instanceof Expr\Array_) {
            $name = $this->optionString($action, 'as') ?? $name;
            $middleware = [...$middleware, ...$this->optionList($action, 'middleware')];
        }

        [$controller, $callable, $confidence, $note] = $this->action($action, $context);

        $definition = new RouteDefinition(
            strtoupper($method),
            $context->uri($uri),
            $controller,
            $callable,
            $this->fullName($context, $name),
            [...$context->middleware, ...$middleware],
            $path,
            $base['line'],
            $confidence,
            $note,
        );

        if ($controller === null && $callable === null) {
            $this->declined[] = $definition;

            return;
        }

        $this->routes[] = $definition;
    }

    /**
     * @return array{?string, ?string, Confidence, ?string}
     */
    private function action(?Expr $action, RouteContext $context): array
    {
        if (! $action instanceof Expr) {
            return [$context->controller, null, Confidence::Inferred, 'no action given; the group names the controller'];
        }

        if ($action instanceof Expr\Closure || $action instanceof Expr\ArrowFunction) {
            return [null, 'closure', Confidence::Resolved, 'answered by a closure, so there is no controller to point at'];
        }

        // `['uses' => 'FilterController', 'as' => …]` — the options-array form, where the
        // action is one entry among several. Reading it as a callable pair finds nothing.
        if ($action instanceof Expr\Array_) {
            foreach ($action->items as $item) {
                if ($item->key instanceof Expr && LiteralValue::scalar($item->key) === 'uses') {
                    return $this->action($item->value, $context);
                }
            }
        }

        // `[Controller::class, 'method']`
        if ($action instanceof Expr\Array_ && count($action->items) === 2) {
            $class = LiteralValue::className($action->items[0]->value);
            $method = LiteralValue::scalar($action->items[1]->value);

            if ($class !== null && is_string($method)) {
                return [$class, $method, Confidence::Resolved, null];
            }
        }

        $literal = LiteralValue::scalar($action);

        // `'Controller@method'` — the spelling the framework used before callable arrays.
        if (is_string($literal) && str_contains($literal, '@')) {
            [$class, $method] = explode('@', $literal, 2);

            return str_starts_with($class, '\\')
                ? [$context->qualify($class), $method, Confidence::Resolved, null]
                : [$context->qualify($class), $method, Confidence::Inferred, 'named by a string, so it is relative to the route namespace rather than fully qualified'];
        }

        $class = LiteralValue::className($action);

        if ($class !== null) {
            return [$class, '__invoke', Confidence::Resolved, 'a single-action controller'];
        }

        if ($context->controller !== null && is_string($literal)) {
            return [$context->controller, $literal, Confidence::Inferred, 'the method is named by the group\'s controller'];
        }

        // A bare name inside a `'namespace' => …` group: a single-action controller, written
        // the way the framework wanted before callable arrays. Reporting it as unreadable would
        // hide a whole route file's worth of endpoints.
        if (is_string($literal) && $literal !== '') {
            return str_starts_with($literal, '\\')
                ? [$context->qualify($literal), '__invoke', Confidence::Resolved, null]
                : [$context->qualify($literal), '__invoke', Confidence::Inferred, 'named by a string, so it is relative to the route namespace rather than fully qualified'];
        }

        return [null, null, Confidence::Textual, 'the action is not readable from the source'];
    }

    /**
     * @param  array{name: string, args: array<int, Node\Arg>, line: int}  $base
     * @param  list<array{name: string, args: array<int, Node\Arg>, line: int}>  $modifiers
     * @param  list<string>  $middleware
     */
    private function resource(array $base, array $modifiers, bool $api, RouteContext $context, array $middleware, string $path): void
    {
        $name = $this->firstString($base['args']);
        $controller = $this->firstClass(array_slice($base['args'], 1));

        if ($name === null || $controller === null) {
            $this->declined[] = new RouteDefinition('RESOURCE', $name ?? '?', $controller, null, null, $middleware, $path, $base['line'], Confidence::Textual, 'the resource name or its controller is not a literal');

            return;
        }

        $actions = $api ? self::API_ONLY : array_keys(self::RESOURCE);
        $only = $this->modifierList($modifiers, 'only');
        $except = $this->modifierList($modifiers, 'except');

        if ($only !== []) {
            $actions = array_values(array_intersect($actions, $only));
        }

        foreach (array_diff($actions, $except) as $action) {
            [$verb, $suffix] = self::RESOURCE[$action];

            $this->routes[] = new RouteDefinition(
                $verb,
                $context->uri($name.$suffix),
                $controller,
                $action,
                $this->fullName($context, $name.'.'.$action),
                [...$context->middleware, ...$middleware],
                $path,
                $base['line'],
                Confidence::Resolved,
                'generated by '.($api ? 'apiResource()' : 'resource()'),
            );
        }
    }

    private function fullName(RouteContext $context, string $name): ?string
    {
        $full = $context->name.$name;

        return $full === '' ? null : $full;
    }

    /**
     * Peels a chained registration into its links, base first. `Route::` is the only entry
     * point that counts — an unrelated static call with a `group()` in it is not a route.
     *
     * @return list<array{name: string, args: array<int, Node\Arg>, line: int}>
     */
    private function chain(Expr $expression): array
    {
        $links = [];
        $current = $expression;

        while ($current instanceof Expr\MethodCall) {
            if (! $current->name instanceof Node\Identifier) {
                return [];
            }

            array_unshift($links, ['name' => $current->name->toString(), 'args' => Analyser::arguments($current), 'line' => $current->getStartLine()]);
            $current = $current->var;
        }

        if (! $current instanceof Expr\StaticCall || ! $current->class instanceof Node\Name || ! $current->name instanceof Node\Identifier) {
            return [];
        }

        $class = Analyser::resolvedName($current->class);

        if (! str_ends_with($class, '\\Route') && $class !== 'Route') {
            return [];
        }

        array_unshift($links, ['name' => $current->name->toString(), 'args' => Analyser::arguments($current), 'line' => $current->getStartLine()]);

        return $links;
    }

    /**
     * @param  list<array{name: string, args: array<int, Node\Arg>, line: int}>  $modifiers
     */
    private function modifierString(array $modifiers, string $name): ?string
    {
        foreach ($modifiers as $link) {
            if (strcasecmp($link['name'], $name) === 0) {
                return $this->firstString($link['args']);
            }
        }

        return null;
    }

    /**
     * @param  list<array{name: string, args: array<int, Node\Arg>, line: int}>  $modifiers
     * @return list<string>
     */
    private function modifierList(array $modifiers, string $name): array
    {
        foreach ($modifiers as $link) {
            if (strcasecmp($link['name'], $name) === 0) {
                return $this->stringList($link['args']);
            }
        }

        return [];
    }

    /**
     * @param  array<int, Node\Arg>  $arguments
     * @return list<string>
     */
    private function stringList(array $arguments): array
    {
        $values = [];

        foreach ($arguments as $argument) {
            if ($argument->value instanceof Expr\Array_) {
                foreach (LiteralValue::items($argument->value) as $item) {
                    if (is_string($item['value'])) {
                        $values[] = $item['value'];
                    }
                }

                continue;
            }

            $value = LiteralValue::scalar($argument->value);

            if (is_string($value)) {
                $values[] = $value;
            }
        }

        return $values;
    }

    private function optionString(Expr $expression, string $key): ?string
    {
        if (! $expression instanceof Expr\Array_) {
            return null;
        }

        foreach ($expression->items as $item) {
            if ($item->key instanceof Expr && LiteralValue::scalar($item->key) === $key) {
                $value = LiteralValue::scalar($item->value);

                return is_string($value) ? $value : null;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function optionList(Expr $expression, string $key): array
    {
        if (! $expression instanceof Expr\Array_) {
            return [];
        }

        foreach ($expression->items as $item) {
            if ($item->key instanceof Expr && LiteralValue::scalar($item->key) === $key) {
                if ($item->value instanceof Expr\Array_) {
                    return array_values(array_filter(array_column(LiteralValue::items($item->value), 'value'), is_string(...)));
                }

                $single = LiteralValue::scalar($item->value);

                return is_string($single) ? [$single] : [];
            }
        }

        return [];
    }

    /**
     * @param  array<int, Node\Arg>  $arguments
     */
    private function firstString(array $arguments, int $offset = 0): ?string
    {
        $argument = array_values($arguments)[$offset] ?? null;
        $value = $argument === null ? null : LiteralValue::scalar($argument->value);

        return is_string($value) ? $value : null;
    }

    /**
     * @param  array<int, Node\Arg>  $arguments
     */
    private function firstClass(array $arguments): ?string
    {
        $argument = array_values($arguments)[0] ?? null;

        return $argument === null ? null : LiteralValue::className($argument->value);
    }
}

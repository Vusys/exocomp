<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Framework\Laravel;

use PhpParser\Node;
use PhpParser\NodeFinder;
use RuntimeException;
use Vusys\Exocomp\Analysis\Analyser;
use Vusys\Exocomp\Index\Index;
use Vusys\Exocomp\Project\Project;
use Vusys\Exocomp\Source\ParseFailure;
use Vusys\Exocomp\Source\SourceFile;
use Vusys\Exocomp\Symbol\Confidence;

/**
 * The application's routes, as Laravel itself lists them.
 *
 * Reading the route files can only approximate the table: routes a service provider loads, the
 * prefix and namespace it wraps a file in, macros and package routes are all decided by running
 * the application. Laravel writes the real table down in two forms, and both are data, so this
 * reads one of them rather than running anything:
 *
 * - `bootstrap/cache/routes-v7.php`, written by `php artisan route:cache`: an array literal,
 *   parsed and never executed;
 * - the output of `php artisan route:list --json`, saved to `exocomp-routes.json` beside
 *   `exocomp-rules.json`, or to a path given with `--routes`.
 *
 * With neither, there is no route table to answer from, and that is an error rather than a
 * guess. The route files are still read, but only to give each route the file and line it is
 * declared at.
 */
final class RouteTable
{
    public const string CACHE = 'bootstrap/cache/routes-v7.php';

    public const string LISTING = 'exocomp-routes.json';

    /** @var list<RouteDefinition> */
    private array $routes = [];

    /** @var list<RouteDefinition> */
    private array $declined = [];

    /** @var list<string> */
    private array $notes = [];

    private function __construct(public readonly string $source) {}

    /**
     * @param  ?string  $given  a `route:list --json` file named on the command line
     * @param  list<RouteDefinition>  $declared  the routes read from the route files, for their positions
     *
     * @throws RuntimeException when there is no route table to read
     */
    public static function forProject(Project $project, ?string $given, array $declared, string $routesDirectory): self
    {
        $cache = $project->path(self::CACHE);
        $listing = $given ?? $project->path(self::LISTING);

        if ($given !== null && ! is_file($given) && ! is_file($project->path($given))) {
            throw new RuntimeException(sprintf('no route list at %s', $given));
        }

        $listing = is_file($listing) ? $listing : $project->path($listing);

        $table = match (true) {
            $given === null && is_file($cache) => self::fromCache($project, $cache),
            is_file($listing) => self::fromListing($project, $listing),
            default => throw new RuntimeException(sprintf(
                'routes are read from the table Laravel builds, and there is none: run `php artisan route:list --json > %s` (or `php artisan route:cache`, which writes %s) and ask again',
                self::LISTING,
                self::CACHE,
            )),
        };

        $table->placeIn($declared);
        $table->checkAge($project, $routesDirectory);

        return $table;
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

    /**
     * @return list<string>
     */
    public function notes(): array
    {
        return [sprintf('routes read from %s', $this->source), ...$this->notes];
    }

    public function filesRead(): int
    {
        return 1;
    }

    private static function fromCache(Project $project, string $path): self
    {
        $display = $project->displayPath($path);
        $table = new self($display);

        try {
            [, $statements] = new Analyser()->analyseWithAst(SourceFile::fromPath($path, $display));
        } catch (ParseFailure $failure) {
            throw new RuntimeException(sprintf('%s could not be parsed: %s', $display, $failure->getMessage()), 0, $failure);
        }

        $compiled = null;

        foreach (new NodeFinder()->findInstanceOf($statements, Node\Expr\MethodCall::class) as $call) {
            if ($call->name instanceof Node\Identifier && $call->name->toString() === 'setCompiledRoutes') {
                $compiled = Analyser::arguments($call)[0]->value ?? null;
            }
        }

        $routes = $compiled instanceof Node\Expr ? self::value($compiled) : null;
        $attributes = is_array($routes) && is_array($routes['attributes'] ?? null) ? $routes['attributes'] : null;

        if ($attributes === null) {
            throw new RuntimeException(sprintf('%s is not a route cache this tool can read', $display));
        }

        foreach ($attributes as $name => $route) {
            if (! is_array($route)) {
                continue;
            }

            $action = is_array($route['action'] ?? null) ? $route['action'] : [];
            $methods = is_array($route['methods'] ?? null) ? array_filter($route['methods'], is_string(...)) : [];
            $uses = is_string($action['uses'] ?? null) ? $action['uses'] : null;
            $middleware = is_array($action['middleware'] ?? null) ? array_values(array_filter($action['middleware'], is_string(...))) : [];

            $table->add(
                implode('|', $methods),
                is_string($route['uri'] ?? null) ? $route['uri'] : '?',
                $uses === null || str_starts_with($uses, 'O:') ? 'Closure' : $uses,
                is_string($name) && ! str_starts_with($name, 'generated::') ? $name : null,
                $middleware,
                $display,
                0,
            );
        }

        return $table;
    }

    private static function fromListing(Project $project, string $path): self
    {
        $display = $project->displayPath($path);
        $table = new self($display);
        $contents = file_get_contents($path);
        $decoded = $contents === false ? null : json_decode($contents, true);

        if (! is_array($decoded)) {
            throw new RuntimeException(sprintf('%s is not the output of `php artisan route:list --json`', $display));
        }

        foreach ($decoded as $route) {
            if (! is_array($route)) {
                continue;
            }

            // `path` is `file:line`, given for a closure: where it is declared.
            $where = is_string($route['path'] ?? null) && preg_match('/^(.*):(\d+)$/', $route['path'], $match) === 1 ? [$match[1], (int) $match[2]] : [$display, 0];

            $table->add(
                is_string($route['method'] ?? null) ? $route['method'] : '?',
                is_string($route['uri'] ?? null) ? $route['uri'] : '?',
                is_string($route['action'] ?? null) ? $route['action'] : 'Closure',
                is_string($route['name'] ?? null) ? $route['name'] : null,
                is_array($route['middleware'] ?? null) ? array_values(array_filter($route['middleware'], is_string(...))) : [],
                $where[0],
                $where[1],
            );
        }

        return $table;
    }

    /**
     * @param  list<string>  $middleware
     */
    private function add(string $method, string $uri, string $action, ?string $name, array $middleware, string $path, int $line): void
    {
        $uri = '/'.ltrim($uri, '/');

        // `App\Http\Controllers\OrderController@store`, or an invokable class.
        [$controller, $callable, $note] = match (true) {
            $action === 'Closure' => [null, 'closure', 'answered by a closure, so there is no controller to point at'],
            str_contains($action, '@') => [...explode('@', $action, 2), null],
            default => [$action, '__invoke', 'a single-action controller'],
        };

        $this->routes[] = new RouteDefinition($method, $uri, $controller, $callable, $name, $middleware, $path, $line, Confidence::Resolved, $note);
    }

    /**
     * Gives each route the file and line the route files declare it at, where they can say.
     *
     * @param  list<RouteDefinition>  $declared
     */
    private function placeIn(array $declared): void
    {
        $positions = [];

        foreach ($declared as $route) {
            foreach (explode('|', $route->method) as $verb) {
                $positions[strtoupper($verb).' '.$this->shape($route->uri)] ??= [$route->path, $route->line];
            }
        }

        foreach ($this->routes as $index => $route) {
            if ($route->line !== 0) {
                continue;
            }

            foreach (explode('|', $route->method) as $verb) {
                $position = $positions[strtoupper($verb).' '.$this->shape($route->uri)] ?? null;

                if ($position !== null) {
                    $this->routes[$index] = new RouteDefinition($route->method, $route->uri, $route->controller, $route->action, $route->name, $route->middleware, $position[0], $position[1], $route->confidence, $route->note);

                    break;
                }
            }
        }
    }

    /**
     * A URI with its parameters' names left out: `/orders/{order}` and `/orders/{id}` are one route.
     */
    private function shape(string $uri): string
    {
        return (string) preg_replace('/\{[^}]+\}/', '{}', '/'.trim($uri, '/'));
    }

    /** Route files this much newer than the table are a change; less is a checkout's spread. */
    private const int CHECKOUT_SPREAD = 300;

    /**
     * A table older than the route files may be missing what was added since.
     *
     * File times alone said so after every fresh clone, which writes files in any order (#112).
     * So the table and the route files are hashed each time they are read: once this table has
     * been seen beside other route files, a change to them is a change. Before that, only route
     * files newer than the table by more than a checkout's spread count.
     */
    private function checkAge(Project $project, string $routesDirectory): void
    {
        $table = $project->path($this->source);
        $tableTime = @filemtime($table);

        if ($tableTime === false || ! is_dir($routesDirectory)) {
            return;
        }

        $files = glob($routesDirectory.'/*.php') ?: [];
        sort($files);
        $tableHash = (string) @hash_file('xxh128', $table);
        $routesHash = hash('xxh128', implode("\n", array_map(static fn (string $file): string => $file.' '.@hash_file('xxh128', $file), $files)));

        $record = Index::cacheDirectory().'/routes/'.hash('xxh128', $project->root.'|'.$this->source).'.json';
        $seen = is_file($record) ? json_decode((string) file_get_contents($record), true) : null;
        $seen = is_array($seen) && is_string($seen['table'] ?? null) && is_string($seen['routes'] ?? null) ? $seen : null;

        if ($seen === null || $seen['table'] !== $tableHash) {
            // A table not seen before: keep what the route files are now, and judge by time.
            @mkdir(dirname($record), 0o777, true);
            @file_put_contents($record, (string) json_encode(['table' => $tableHash, 'routes' => $routesHash]));

            foreach ($files as $file) {
                if ((@filemtime($file) ?: 0) > $tableTime + self::CHECKOUT_SPREAD) {
                    $this->stale($project, $file);

                    return;
                }
            }

            return;
        }

        if ($seen['routes'] !== $routesHash) {
            $this->notes[] = sprintf('the route files have changed since %s was written, so the table may be out of date — regenerate it', $this->source);
        }
    }

    private function stale(Project $project, string $file): void
    {
        $this->notes[] = sprintf('%s is newer than %s, so the table may be out of date — regenerate it', $project->displayPath($file), $this->source);
    }

    /**
     * The value of an array literal, as `var_export()` wrote it.
     */
    private static function value(Node\Expr $expression): mixed
    {
        return match (true) {
            $expression instanceof Node\Scalar\String_, $expression instanceof Node\Scalar\Int_, $expression instanceof Node\Scalar\Float_ => $expression->value,
            $expression instanceof Node\Expr\ConstFetch => match (strtolower($expression->name->toString())) {
                'true' => true,
                'false' => false,
                default => null,
            },
            $expression instanceof Node\Expr\UnaryMinus && $expression->expr instanceof Node\Scalar\Int_ => -$expression->expr->value,
            $expression instanceof Node\Expr\Array_ => self::arrayValue($expression),
            default => null,
        };
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function arrayValue(Node\Expr\Array_ $array): array
    {
        $values = [];

        foreach ($array->items as $item) {
            $key = $item->key instanceof Node\Expr ? self::value($item->key) : null;
            $value = self::value($item->value);

            if (is_string($key) || is_int($key)) {
                $values[$key] = $value;
            } else {
                $values[] = $value;
            }
        }

        return $values;
    }
}

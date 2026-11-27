<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Framework;

use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Framework\Laravel\RouteContext;
use Vusys\Exocomp\Framework\Laravel\RouteDefinition;
use Vusys\Exocomp\Framework\Laravel\RouteNamespace;
use Vusys\Exocomp\Framework\Laravel\RouteReader;
use Vusys\Exocomp\Index\Index;
use Vusys\Exocomp\Project\AutoloadMap;
use Vusys\Exocomp\Project\ClassSource;
use Vusys\Exocomp\Project\Project;
use Vusys\Exocomp\Project\SymbolLocator;
use Vusys\Exocomp\Symbol\Confidence;
use Vusys\Exocomp\Tests\Support\Cli;

/**
 * The route reader, asserted directly, because the exact URI is the whole answer.
 *
 * A route table where the paths are nearly right is worse than none: it is the entry point
 * every reachability question starts from, so an endpoint under the wrong prefix sends a reader
 * looking in the wrong place with no sign that anything is off.
 */
final class RouteReaderTest extends TestCase
{
    public function test_prefixes_join_with_exactly_one_slash(): void
    {
        $context = new RouteContext()->with('admin/', '', [], null)->with('/reports', '', [], null);

        $this->assertSame('/admin/reports/monthly', $context->uri('/monthly'));
        $this->assertSame('/admin/reports', $context->uri(''));
    }

    public function test_an_empty_prefix_and_an_empty_path_give_the_root(): void
    {
        $this->assertSame('/', new RouteContext()->uri(''));
        $this->assertSame('/', new RouteContext()->uri('/'));
    }

    public function test_a_nested_group_concatenates_both_prefixes(): void
    {
        $this->assertSame('/admin/reports/monthly', $this->route('admin.reports.monthly')->uri);
    }

    public function test_names_and_middleware_accumulate_down_the_nesting(): void
    {
        $route = $this->route('admin.reports.monthly');

        $this->assertSame(['auth'], $route->middleware);
        $this->assertSame('App\Http\Controllers\OrderController', $route->controller);
        $this->assertSame('show', $route->action);
    }

    public function test_a_callable_pair_is_resolved(): void
    {
        $route = $this->route('admin.orders');

        $this->assertSame('GET', $route->method);
        $this->assertSame(Confidence::Resolved, $route->confidence);
        $this->assertSame('App\Http\Controllers\OrderController::index', $route->target());
    }

    public function test_a_resource_expands_to_the_actions_it_registers_and_no_others(): void
    {
        $names = array_map(
            static fn (RouteDefinition $route): ?string => $route->name,
            array_values(array_filter(
                $this->routes(),
                static fn (RouteDefinition $route): bool => str_starts_with((string) $route->name, 'orders.'),
            )),
        );

        // `->only(['index', 'show'])`, so the other five must not appear.
        $this->assertSame(['orders.index', 'orders.show'], $names);
    }

    public function test_a_resource_route_uses_the_frameworks_own_uris(): void
    {
        $this->assertSame('/api/orders', $this->route('orders.index')->uri);
        $this->assertSame('/api/orders/{order}', $this->route('orders.show')->uri);
    }

    public function test_a_bare_name_inside_a_namespaced_group_is_qualified_and_inferred(): void
    {
        $route = $this->route('legacy.orders');

        $this->assertSame('Legacy\OrderListController', $route->controller);
        $this->assertSame('__invoke', $route->action);
        $this->assertSame(Confidence::Inferred, $route->confidence);
    }

    public function test_the_options_array_form_is_read(): void
    {
        $route = $this->route('api.health');

        $this->assertSame('HealthController', $route->controller);
        $this->assertSame('/api/health', $route->uri);
    }

    public function test_a_closure_route_has_no_controller_and_says_so(): void
    {
        $route = $this->route('home');

        $this->assertNull($route->controller);
        $this->assertSame('closure', $route->target());
        $this->assertStringContainsString('closure', (string) $route->note);
    }

    public function test_a_route_whose_uri_is_computed_is_declined_rather_than_dropped(): void
    {
        $declined = new RouteReader()->readDirectory(
            Cli::laravelRoot().'/routes',
            static fn (string $absolute): string => 'routes/'.basename($absolute),
        )->declined();

        $this->assertCount(1, $declined);
        $this->assertSame(Confidence::Textual, $declined[0]->confidence);
        $this->assertStringContainsString('not a literal', (string) $declined[0]->note);
    }

    public function test_a_root_namespace_qualifies_a_bare_name(): void
    {
        $route = $this->named($this->rooted(), 'legacy.orders');

        // The group namespace joins on after the root, in that order.
        $this->assertSame('App\Http\Controllers\Legacy\OrderListController', $route->controller);
        $this->assertSame(Confidence::Inferred, $route->confidence);
    }

    public function test_a_leading_backslash_means_the_name_is_already_absolute(): void
    {
        $route = $this->named($this->rooted(), 'exports');

        // Not `App\Http\Controllers\App\Http\Controllers\…`, which is what prefixing
        // regardless would produce — a name that resolves to nothing and looks authoritative.
        $this->assertSame('App\Http\Controllers\OrderController', $route->controller);
        $this->assertSame('index', $route->action);
        $this->assertSame(Confidence::Resolved, $route->confidence);
        $this->assertNull($route->note);
    }

    public function test_the_root_namespace_is_read_from_the_applications_own_route_provider(): void
    {
        $project = Project::at(Cli::laravelRoot());
        $map = AutoloadMap::forProject($project);
        $graph = Index::open($project, $map)->graph(validate: false);

        $root = new RouteNamespace($graph, new ClassSource($project, $graph, new SymbolLocator($project, $map)))->root();

        $this->assertSame('App\Http\Controllers', $root);
    }

    public function test_both_route_files_are_read(): void
    {
        $this->assertSame(2, $this->reader()->filesRead());
    }

    public function test_a_directory_that_is_not_there_says_so(): void
    {
        $reader = new RouteReader()->readDirectory('/no/such/routes', static fn (string $p): string => $p);

        $this->assertSame([], $reader->routes());
        $this->assertStringContainsString('is not there', $reader->notes()[0]);
    }

    private function rooted(): RouteReader
    {
        return new RouteReader('App\Http\Controllers')->readDirectory(
            Cli::laravelRoot().'/routes',
            static fn (string $absolute): string => 'routes/'.basename($absolute),
        );
    }

    private function named(RouteReader $reader, string $name): RouteDefinition
    {
        foreach ($reader->routes() as $route) {
            if ($route->name === $name) {
                return $route;
            }
        }

        $this->fail("no route named {$name}");
    }

    private function reader(): RouteReader
    {
        return new RouteReader()->readDirectory(
            Cli::laravelRoot().'/routes',
            static fn (string $absolute): string => 'routes/'.basename($absolute),
        );
    }

    /**
     * @return list<RouteDefinition>
     */
    private function routes(): array
    {
        return $this->reader()->routes();
    }

    private function route(string $name): RouteDefinition
    {
        foreach ($this->routes() as $route) {
            if ($route->name === $name) {
                return $route;
            }
        }

        $this->fail("no route named {$name}");
    }
}

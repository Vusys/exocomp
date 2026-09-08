<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Graph;

use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Framework\Laravel\ConfigReader;
use Vusys\Exocomp\Framework\Laravel\ContainerReader;
use Vusys\Exocomp\Graph\CodeEdges;
use Vusys\Exocomp\Graph\Edge;
use Vusys\Exocomp\Graph\LaravelEdges;
use Vusys\Exocomp\Graph\Stop;
use Vusys\Exocomp\Graph\Target;
use Vusys\Exocomp\Graph\Walk;
use Vusys\Exocomp\Index\Index;
use Vusys\Exocomp\Project\AutoloadMap;
use Vusys\Exocomp\Project\ClassSource;
use Vusys\Exocomp\Project\Project;
use Vusys\Exocomp\Project\SymbolLocator;
use Vusys\Exocomp\Symbol\Confidence;
use Vusys\Exocomp\Tests\Support\Cli;

/**
 * The walk, asserted edge by edge rather than through the rendered answer.
 *
 * A verdict is one word and it is the same word whether the graph underneath it was right. The
 * things that decide whether it was — which edges exist, what kind each is, what confidence it
 * carries, and which of them were reported as unfollowable — are only visible here, so this is
 * where they are checked.
 */
final class WalkTest extends TestCase
{
    public function test_a_dispatched_job_continues_at_its_own_handler(): void
    {
        $edge = $this->edge('App\Services\OrderService::place', 'App\Jobs\ProcessOrder::handle');

        $this->assertSame('dispatch', $edge->kind);
        $this->assertSame(Confidence::Resolved, $edge->confidence);
        $this->assertStringContainsString('the job handles itself', $edge->reason);
    }

    public function test_an_event_continues_at_every_registered_listener(): void
    {
        $targets = array_map(
            static fn (Edge $edge): string => $edge->to,
            array_values(array_filter(
                $this->walk()->edgesFrom('App\Services\OrderService::place'),
                static fn (Edge $edge): bool => $edge->kind === 'listens',
            )),
        );

        sort($targets);

        $this->assertSame(
            ['App\Listeners\NotifyCustomer::handle', 'App\Listeners\RecordMetrics::handle'],
            $targets,
        );
    }

    public function test_a_facade_call_continues_at_the_class_the_container_resolves(): void
    {
        $edge = $this->edge('App\Services\ReportDispatcher::run', 'App\Services\ReportBuilder::build');

        $this->assertSame('facade', $edge->kind);
        $this->assertSame(Confidence::Inferred, $edge->confidence);
        $this->assertStringContainsString('reporting', $edge->reason);
    }

    public function test_a_class_resolved_out_of_the_container_continues_at_the_method_called_on_it(): void
    {
        $edge = $this->edge('App\Services\ReportDispatcher::run', 'App\Services\CsvReportWriter::write');

        $this->assertSame('binding', $edge->kind);
        $this->assertSame(Confidence::Inferred, $edge->confidence);
        $this->assertStringContainsString('bound to it', $edge->reason);
    }

    public function test_a_resolution_that_is_called_immediately_is_one_edge_and_not_two(): void
    {
        $bindings = array_values(array_filter(
            $this->walk()->edgesFrom('App\Services\ReportDispatcher::run'),
            static fn (Edge $edge): bool => $edge->kind === 'binding',
        ));

        // The method called on the resolved instance, not that *and* its constructor: the
        // container hands back an object and the call lands on it, which is one step.
        $this->assertCount(1, $bindings);
        $this->assertSame('App\Services\CsvReportWriter::write', $bindings[0]->to);
    }

    public function test_a_filled_edge_does_not_hide_an_unfilled_one_on_the_same_line(): void
    {
        $walk = $this->walk();
        $edges = $walk->edgesFrom('App\Services\ReportDispatcher::queue');
        $walk->reach('App\Services\ReportDispatcher::queue', Target::parse('Nothing\AtAll::ever'));

        // The dispatch on that line is resolved and reported as an edge...
        $this->assertContains('App\Jobs\ProcessOrder::handle', array_map(static fn (Edge $edge): string => $edge->to, $edges));

        // ...and the unresolvable call written inside its argument is still a gap. Matching a
        // framework edge to a call by line alone would have swallowed it.
        $unresolved = array_values(array_filter(
            $walk->stops(),
            static fn (Stop $stop): bool => $stop->kind === Stop::UNRESOLVED,
        ));

        $this->assertCount(1, $unresolved);
        $this->assertSame('$unknown->order()', $unresolved[0]->detail);
    }

    public function test_a_call_on_an_interface_fans_out_to_its_implementations(): void
    {
        $edges = array_values(array_filter(
            $this->walk()->edgesFrom('App\Http\Controllers\OrderController::index'),
            static fn (Edge $edge): bool => $edge->kind === 'implementation',
        ));

        $this->assertSame(
            ['App\Services\NullReportWriter::write', 'App\Services\PdfReportWriter::write'],
            array_map(static fn (Edge $edge): string => $edge->to, $edges),
        );
        $this->assertSame(Confidence::Inferred, $edges[0]->confidence);
        $this->assertStringContainsString('nothing at the call site says which', $edges[0]->reason);
    }

    public function test_an_indirect_edge_is_addressed_at_the_call_it_came_from(): void
    {
        // Not at the declaration it points to. The address a reader acts on is the line they
        // would have had to read four files to understand.
        foreach ($this->walk()->edgesFrom('App\Http\Controllers\OrderController::index') as $edge) {
            if ($edge->kind !== 'binding' && $edge->kind !== 'implementation') {
                continue;
            }

            $this->assertSame('app/Http/Controllers/OrderController.php', $edge->path);
            $this->assertSame(21, $edge->line);
            $this->assertSame(16, $edge->column);
            $this->assertSame('App\Http\Controllers\OrderController::index', $edge->from);
        }
    }

    public function test_the_container_helper_the_alias_and_the_method_all_produce_the_edge(): void
    {
        $edges = array_values(array_filter(
            $this->walk()->edgesFrom('App\Services\ReportDispatcher::alternates'),
            static fn (Edge $edge): bool => $edge->kind === 'binding',
        ));

        $targets = array_map(static fn (Edge $edge): string => $edge->to, $edges);
        sort($targets);

        // `resolve(X)->write()` lands on the method; `app()->make(X)` on its own lands on the
        // constructor, because nothing is called on what came back.
        $this->assertSame(
            ['App\Services\CsvReportWriter::__construct', 'App\Services\CsvReportWriter::write'],
            $targets,
        );
    }

    public function test_the_fan_out_cap_is_disclosed_when_it_bites(): void
    {
        $walk = $this->walk(fanout: 1);
        $walk->edgesFrom('App\Http\Controllers\OrderController::index');

        // A bound that nobody is told about is indistinguishable from an absence: the answer
        // would list one implementation and read as though there were one.
        $this->assertStringContainsString('App\Contracts\ReportWriter has 3 implementations', implode("\n", $walk->notes()));
        $this->assertStringContainsString('followed 1 of them and stopped', implode("\n", $walk->notes()));
    }

    public function test_nothing_is_said_when_the_cap_does_not_bite(): void
    {
        $walk = $this->walk();
        $walk->edgesFrom('App\Http\Controllers\OrderController::index');

        $this->assertSame([], $walk->notes());
    }

    public function test_a_dependency_is_a_wall_the_walk_reports_rather_than_crosses(): void
    {
        $walk = $this->walk();
        $walk->reach('App\Jobs\ProcessOrder::handle', Target::parse('Nothing\AtAll::ever'));

        $dependencies = array_values(array_filter(
            $walk->stops(),
            static fn (Stop $stop): bool => $stop->kind === Stop::DEPENDENCY,
        ));

        $this->assertNotSame([], $dependencies);
        $this->assertStringStartsWith('vendor/', $dependencies[0]->path);
        $this->assertStringContainsString('--through-vendor', $dependencies[0]->reason);
    }

    public function test_through_vendor_walks_on_instead(): void
    {
        $walk = $this->walk(throughDependencies: true);
        $walk->reach('App\Jobs\ProcessOrder::handle', Target::parse('Nothing\AtAll::ever'));

        $this->assertSame([], array_values(array_filter(
            $walk->stops(),
            static fn (Stop $stop): bool => $stop->kind === Stop::DEPENDENCY,
        )));
    }

    public function test_a_built_in_function_is_the_edge_of_the_world_and_not_a_gap(): void
    {
        $walk = $this->walk();
        $edges = $walk->edgesFrom('App\Services\PdfReportWriter::write');

        // The call is an edge, so the answer says the function is reached...
        $this->assertContains('str_ends_with', array_map(static fn (Edge $edge): string => $edge->to, $edges));

        // ...but a built-in with no source behind it is not something the walk lost, and
        // listing it as an edge it could not follow would bury the ones it did lose.
        $walk->reach('App\Services\PdfReportWriter::write', Target::parse('Nothing\AtAll::ever'));

        foreach ($walk->stops() as $stop) {
            $this->assertNotSame('str_ends_with', $stop->detail);
        }

        $this->assertSame([], $walk->stops());
    }

    public function test_the_shortest_path_is_the_one_returned(): void
    {
        $reach = $this->walk()->reach('App\Services\OrderService::place', Target::parse('App\Models\Order::save'));

        $this->assertTrue($reach->found);
        $this->assertCount(2, $reach->path);
        $this->assertSame('App\Jobs\ProcessOrder::handle', $reach->path[0]->to);
        $this->assertSame(Confidence::Resolved, $reach->confidence());
    }

    public function test_a_path_is_worth_its_weakest_step(): void
    {
        $reach = $this->walk()->reach('App\Services\ReportDispatcher::run', Target::parse('App\Services\ReportBuilder::build'));

        $this->assertTrue($reach->found);
        $this->assertSame(Confidence::Inferred, $reach->confidence());
    }

    public function test_the_destination_being_the_start_is_a_path_of_no_steps(): void
    {
        $reach = $this->walk()->reach('App\Models\Order::save', Target::parse('App\Models\Order'));

        $this->assertTrue($reach->found);
        $this->assertSame([], $reach->path);
    }

    public function test_a_static_dispatch_does_not_also_report_its_own_forward_as_a_gap(): void
    {
        $walk = $this->walk();
        $walk->reach('App\Services\OrderService::place', Target::parse('Nothing\AtAll::ever'));

        // `ProcessOrder::dispatch(…)` has no body — the method is on a framework trait — but the
        // walk was already handed the edge to the job's `handle()` from that same call, so the
        // forward is a gap that was filled rather than one that was found.
        foreach ($walk->stops() as $stop) {
            $this->assertNotSame('App\Jobs\ProcessOrder::dispatch', $stop->detail);
        }
    }

    public function test_a_path_is_traced_back_through_the_edges_that_found_it(): void
    {
        $reach = $this->walk()->reach('App\Http\Controllers\OrderController::store', Target::parse('App\Models\Order::save'));

        $this->assertTrue($reach->found);

        // Every step begins where the one before it ended, and the first begins at the start.
        $this->assertSame('App\Http\Controllers\OrderController::store', $reach->path[0]->from);

        foreach ($reach->path as $index => $edge) {
            if ($index > 0) {
                $this->assertSame($reach->path[$index - 1]->to, $edge->from);
            }
        }

        $this->assertSame('App\Models\Order::save', $reach->path[count($reach->path) - 1]->to);
    }

    public function test_a_cycle_terminates_without_the_depth_limit_doing_the_work(): void
    {
        // Six steps of budget and two methods calling each other: the walk must end because it
        // has seen both, not because it ran out of depth.
        $reach = $this->walk()->reach('App\Services\RetryLoop::attempt', Target::parse('Nothing\AtAll::ever'));

        $this->assertFalse($reach->found);
        $this->assertFalse($reach->exhausted);
        $this->assertSame(2, $reach->expanded);
    }

    public function test_a_declaration_reachable_two_ways_is_visited_once(): void
    {
        // Both the job's handler and a listener call `save()` on the same model. It is one
        // declaration, and listing it per route in would make a neighbourhood read as larger
        // than it is.
        $found = $this->walk()->neighbourhood('App\Services\OrderService::place', 3);
        $arrivals = array_filter($found, static fn (array $one): bool => $one[1]->to === 'App\Models\Order::save');

        $this->assertCount(1, $arrivals);
    }

    public function test_a_node_budget_that_bites_is_reported_as_such(): void
    {
        $reach = $this->walk(maxNodes: 1)->reach('App\Services\OrderService::place', Target::parse('App\Models\Order::save'));

        $this->assertFalse($reach->found);
        $this->assertTrue($reach->exhausted);
        $this->assertSame(1, $reach->expanded);
    }

    public function test_a_node_budget_bounds_a_neighbourhood_too(): void
    {
        $wide = $this->walk()->neighbourhood('App\Services\OrderService::place', 3);
        $narrow = $this->walk(maxNodes: 1)->neighbourhood('App\Services\OrderService::place', 3);

        $this->assertGreaterThan(count($narrow), count($wide));
    }

    public function test_the_same_stop_asked_for_twice_is_recorded_once(): void
    {
        $walk = $this->walk();
        $walk->edgesFrom('App\Services\ReportDispatcher::queue');
        $first = count($walk->stops());

        $walk->edgesFrom('App\Services\ReportDispatcher::queue');

        $this->assertSame(1, $first);
        $this->assertCount($first, $walk->stops());
    }

    public function test_a_depth_budget_that_bites_is_reported_as_such(): void
    {
        $reach = $this->walk(maxDepth: 1)->reach('App\Services\OrderService::place', Target::parse('App\Models\Order::save'));

        $this->assertFalse($reach->found);
        $this->assertTrue($reach->exhausted);
    }

    public function test_a_complete_search_is_distinguishable_from_a_curtailed_one(): void
    {
        $reach = $this->walk()->reach('App\Services\ReportBuilder::build', Target::parse('App\Models\Order::save'));

        $this->assertFalse($reach->found);
        $this->assertFalse($reach->exhausted);
        $this->assertSame([], $reach->stops);
    }

    public function test_a_neighbourhood_records_the_step_each_declaration_was_found_at(): void
    {
        $found = $this->walk()->neighbourhood('App\Services\OrderService::place', 2);
        $depths = [];

        foreach ($found as [$depth, $edge]) {
            $depths[$edge->to] = $depth;
        }

        $this->assertSame(1, $depths['App\Jobs\ProcessOrder::handle']);
        $this->assertSame(2, $depths['App\Models\Order::save']);
    }

    private function edge(string $from, string $to): Edge
    {
        foreach ($this->walk()->edgesFrom($from) as $edge) {
            if ($edge->to === $to) {
                return $edge;
            }
        }

        $this->fail("no edge from {$from} to {$to}");
    }

    private function walk(int $maxDepth = 6, bool $throughDependencies = false, int $fanout = 12, int $maxNodes = 1500): Walk
    {
        $project = Project::at(Cli::laravelRoot());
        $map = AutoloadMap::forProject($project);
        $index = Index::open($project, $map);
        $graph = $index->graph(validate: false);
        $locator = new SymbolLocator($project, $map);
        $source = new ClassSource($project, $graph, $locator);

        return new Walk(
            code: new CodeEdges($project, $graph, $source, $locator),
            framework: new LaravelEdges(
                $project,
                $graph,
                $source,
                $index->store,
                static function () use ($project, $graph, $source): ContainerReader {
                    $config = new ConfigReader;
                    $config->readDirectory(
                        $project->path('config'),
                        static fn (string $absolute): string => $project->displayPath($absolute),
                    );

                    return new ContainerReader($graph, $source)->read(
                        static fn (string $key): array => $config->matching($key),
                    );
                },
                $fanout,
            ),
            maxDepth: $maxDepth,
            maxNodes: $maxNodes,
            throughDependencies: $throughDependencies,
        );
    }
}

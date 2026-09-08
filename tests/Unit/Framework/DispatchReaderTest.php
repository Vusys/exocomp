<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Framework;

use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Framework\Laravel\DispatchEdge;
use Vusys\Exocomp\Framework\Laravel\DispatchReader;
use Vusys\Exocomp\Index\Index;
use Vusys\Exocomp\Project\AutoloadMap;
use Vusys\Exocomp\Project\ClassSource;
use Vusys\Exocomp\Project\Project;
use Vusys\Exocomp\Project\SymbolLocator;
use Vusys\Exocomp\Symbol\Confidence;
use Vusys\Exocomp\Tests\Support\Cli;

/**
 * The dispatch reader, asserted directly.
 *
 * Three ways a receiver is established and they are not equally certain, so the confidence on
 * each is the load-bearing part: a job handling itself is a fact, a `$listen` entry is a
 * declaration, and discovery by parameter type is a convention that may or may not be enabled.
 */
final class DispatchReaderTest extends TestCase
{
    public function test_both_spellings_of_a_dispatch_are_found(): void
    {
        $counterparts = array_map(
            static fn (DispatchEdge $edge): ?string => $edge->counterpart,
            $this->read('App\Jobs\ProcessOrder')->sites(),
        );

        $this->assertContains('dispatch()', $counterparts);
        $this->assertContains('ProcessOrder::dispatch()', $counterparts);
    }

    public function test_a_job_handles_itself_and_that_is_a_fact_about_the_class(): void
    {
        $receivers = $this->read('App\Jobs\ProcessOrder')->receivers();

        $this->assertCount(1, $receivers);
        $this->assertSame('handler', $receivers[0]->kind);
        $this->assertSame(Confidence::Resolved, $receivers[0]->confidence);
        $this->assertSame('App\Jobs\ProcessOrder::handle', $receivers[0]->counterpart);
    }

    public function test_the_event_helper_is_recognised_as_a_dispatch(): void
    {
        $sites = $this->read('App\Events\OrderPlaced')->sites();

        $this->assertCount(1, $sites);
        $this->assertSame('event()', $sites[0]->counterpart);
    }

    public function test_a_registered_listener_is_resolved_and_a_discovered_one_is_inferred(): void
    {
        $byClass = [];

        foreach ($this->read('App\Events\OrderPlaced')->receivers() as $edge) {
            $byClass[(string) $edge->counterpart] = $edge;
        }

        $this->assertSame(Confidence::Resolved, $byClass['App\Listeners\NotifyCustomer']->confidence);
        $this->assertStringContainsString('$listen', (string) $byClass['App\Listeners\NotifyCustomer']->note);

        $this->assertSame(Confidence::Inferred, $byClass['App\Listeners\RecordMetrics']->confidence);
        $this->assertStringContainsString('discovers listeners', (string) $byClass['App\Listeners\RecordMetrics']->note);
    }

    public function test_a_listener_both_declared_and_discoverable_is_reported_once_as_declared(): void
    {
        $receivers = $this->read('App\Events\OrderPlaced')->receivers();
        $names = array_map(static fn (DispatchEdge $edge): ?string => $edge->counterpart, $receivers);

        $this->assertSame(count($names), count(array_unique($names)));
    }

    public function test_an_event_does_not_count_itself_as_its_own_listener(): void
    {
        foreach ($this->read('App\Events\OrderPlaced')->receivers() as $edge) {
            $this->assertNotSame('App\Events\OrderPlaced', $edge->counterpart);
        }
    }

    public function test_a_class_nothing_dispatches_has_no_sites(): void
    {
        $this->assertSame([], $this->read('App\Models\Customer')->sites());
    }

    private function read(string $fqn): DispatchReader
    {
        $project = Project::at(Cli::laravelRoot());
        $map = AutoloadMap::forProject($project);
        $index = Index::open($project, $map);
        $graph = $index->graph(validate: false);

        $short = substr($fqn, (int) strrpos($fqn, '\\') + 1);

        return new DispatchReader(new ClassSource($project, $graph, new SymbolLocator($project, $map)))->read(
            $fqn,
            $index->store->pathsMentioning($project->root, [$short]),
            static fn (string $path): string => $project->path($path),
        );
    }
}

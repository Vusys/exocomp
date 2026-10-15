<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Inference;

use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Index\Index;
use Vusys\Exocomp\Inference\Reference;
use Vusys\Exocomp\Inference\ReferenceFinder;
use Vusys\Exocomp\Project\AutoloadMap;
use Vusys\Exocomp\Project\Project;
use Vusys\Exocomp\Symbol\Confidence;
use Vusys\Exocomp\Symbol\SymbolRef;
use Vusys\Exocomp\Tests\Support\Cli;

/**
 * The two things that buried the answer to "who calls this `create`": the container lookups that
 * were left untyped, and the Eloquent chains that could never have been calls on the target.
 */
final class ContainerAndEloquentReferencesTest extends TestCase
{
    private ReferenceFinder $finder;

    /** @var array<string, Reference> */
    private array $byContext = [];

    protected function setUp(): void
    {
        $project = Project::at(Cli::laravelRoot());
        $index = Index::open($project, AutoloadMap::forProject($project));
        $index->sync();

        $this->finder = new ReferenceFinder($project, $index);

        foreach ($this->finder->find(SymbolRef::parse('App\Services\Enrolment::create')) as $reference) {
            $this->byContext[$reference->context.'@'.$reference->line] = $reference;
        }
    }

    public function test_every_spelling_of_a_container_lookup_is_typed_as_the_class_it_names(): void
    {
        foreach (['helper', 'alias', 'helperMake', 'property', 'facade'] as $method) {
            $reference = $this->in('App\Services\EnrolmentCallers::'.$method);

            $this->assertInstanceOf(Reference::class, $reference, $method);
            $this->assertSame(Confidence::Resolved, $reference->confidence, $method);
        }
    }

    public function test_a_different_class_out_of_the_container_is_not_a_reference(): void
    {
        $this->assertNull($this->in('App\Services\EnrolmentCallers::unrelated'));
    }

    public function test_eloquent_chains_on_a_model_are_excluded_and_counted(): void
    {
        $this->assertNull($this->in('App\Services\EnrolmentCallers::eloquent'));
        $this->assertSame(4, $this->finder->excluded());
    }

    public function test_a_chain_with_a_way_out_stays_unresolved(): void
    {
        $escapes = array_filter($this->byContext, static fn (Reference $r): bool => $r->context === 'App\Services\EnrolmentCallers::escapes');

        $this->assertCount(4, $escapes);

        foreach ($escapes as $reference) {
            $this->assertSame(Confidence::Textual, $reference->confidence);
        }
    }

    public function test_a_bound_interface_is_typed_as_its_binding(): void
    {
        $project = Project::at(Cli::laravelRoot());
        $index = Index::open($project, AutoloadMap::forProject($project));
        $references = new ReferenceFinder($project, $index)->find(SymbolRef::parse('App\Services\CsvReportWriter::write'));
        $dispatcher = array_values(array_filter($references, static fn (Reference $r): bool => $r->context === 'App\Services\ReportDispatcher::run'));

        // Inferred, never resolved: a binding is a fact about boot, and a test or a contextual
        // binding can change it.
        $this->assertCount(1, $dispatcher);
        $this->assertSame(Confidence::Inferred, $dispatcher[0]->confidence);
        $this->assertStringContainsString('bound to it', $dispatcher[0]->reason);
    }

    private function in(string $context): ?Reference
    {
        foreach ($this->byContext as $reference) {
            if ($reference->context === $context) {
                return $reference;
            }
        }

        return null;
    }
}

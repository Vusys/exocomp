<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Framework;

use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Framework\Laravel\Binding;
use Vusys\Exocomp\Framework\Laravel\ConfigReader;
use Vusys\Exocomp\Framework\Laravel\ContainerReader;
use Vusys\Exocomp\Index\Index;
use Vusys\Exocomp\Project\AutoloadMap;
use Vusys\Exocomp\Project\ClassSource;
use Vusys\Exocomp\Project\Project;
use Vusys\Exocomp\Project\SymbolLocator;
use Vusys\Exocomp\Symbol\Confidence;
use Vusys\Exocomp\Tests\Support\Cli;

/**
 * The container reader, asserted directly.
 *
 * Container edges are the ones a language-only call graph is missing, so the exact shape of
 * each — which side is abstract, what kind of registration it was, how well the concrete side
 * is known — is the answer rather than a detail of it.
 */
final class ContainerReaderTest extends TestCase
{
    public function test_an_interface_binding_is_resolved_on_both_sides(): void
    {
        $binding = $this->binding('App\Contracts\ReportWriter');

        $this->assertSame('App\Services\CsvReportWriter', $binding->concrete);
        $this->assertSame('bind', $binding->kind);
        $this->assertSame(Confidence::Resolved, $binding->confidence);
        $this->assertSame('App\Providers\AppServiceProvider', $binding->registeredBy);
    }

    public function test_a_closure_returning_one_new_is_inferred_rather_than_resolved(): void
    {
        $binding = $this->binding('reporting');

        $this->assertSame('App\Services\ReportBuilder', $binding->concrete);
        $this->assertSame('singleton', $binding->kind);
        $this->assertSame(Confidence::Inferred, $binding->confidence);
        $this->assertStringContainsString('one `new`', (string) $binding->note);
    }

    public function test_an_alias_points_at_the_class_it_aliases(): void
    {
        $binding = $this->binding('report.builder');

        $this->assertSame('alias', $binding->kind);
        $this->assertSame('App\Services\ReportBuilder', $binding->concrete);
    }

    public function test_a_contextual_binding_carries_the_class_it_applies_to(): void
    {
        $contextual = null;

        foreach ($this->reader()->bindings() as $binding) {
            if ($binding->kind === 'contextual') {
                $contextual = $binding;
            }
        }

        $this->assertInstanceOf(Binding::class, $contextual);
        $this->assertSame('App\Contracts\ReportWriter', $contextual->abstract);
        $this->assertSame('App\Services\PdfReportWriter', $contextual->concrete);
        $this->assertSame('App\Http\Controllers\OrderController', $contextual->context);
        $this->assertStringContainsString('when resolved for', $contextual->describe());
    }

    public function test_a_factory_building_more_than_one_class_is_declined(): void
    {
        $declined = $this->reader()->declined();

        $this->assertCount(1, $declined);
        $this->assertSame('report.writer.chosen', $declined[0]->abstract);
        $this->assertNull($declined[0]->concrete);
        $this->assertSame(Confidence::Textual, $declined[0]->confidence);
    }

    public function test_concrete_for_finds_a_binding_by_its_abstract_name(): void
    {
        $this->assertNotNull($this->reader()->concreteFor('reporting'));
        $this->assertNull($this->reader()->concreteFor('nothing.bound.here'));
    }

    public function test_a_contextual_binding_is_not_returned_as_the_general_one(): void
    {
        // Asking what `ReportWriter` resolves to must give the default, not the binding that
        // applies to one controller.
        $this->assertSame('App\Services\CsvReportWriter', $this->reader()->concreteFor('App\Contracts\ReportWriter')?->concrete);
    }

    public function test_the_morph_map_is_followed_into_the_config_it_is_registered_from(): void
    {
        $entries = $this->reader()->morphMap();

        $this->assertSame(['order', 'comment'], array_map(static fn (Binding $b): string => $b->abstract, $entries));
        $this->assertSame('App\Models\Order', $entries[0]->concrete);
        $this->assertSame(Confidence::Inferred, $entries[0]->confidence);
        $this->assertStringContainsString('config/morph-map.php', $entries[0]->path);
    }

    public function test_every_provider_in_the_corpus_is_read(): void
    {
        // Three since the macro provider joined the fixture. The count is asserted rather than
        // the bindings alone because a provider silently skipped is how a binding goes missing.
        $this->assertSame(3, $this->reader()->providersRead());
    }

    private function binding(string $abstract): Binding
    {
        $binding = $this->reader()->concreteFor($abstract);

        $this->assertInstanceOf(Binding::class, $binding, "nothing binds {$abstract}");

        return $binding;
    }

    private function reader(): ContainerReader
    {
        $project = Project::at(Cli::laravelRoot());
        $map = AutoloadMap::forProject($project);
        $graph = Index::open($project, $map)->graph(validate: false);

        $config = new ConfigReader;
        $config->readDirectory(
            $project->path('config'),
            static fn (string $absolute): string => $project->displayPath($absolute),
        );

        return new ContainerReader($graph, new ClassSource($project, $graph, new SymbolLocator($project, $map)))
            ->read(static fn (string $key): array => $config->matching($key));
    }
}

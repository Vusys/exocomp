<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Framework;

use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Framework\Laravel\BladeSource;
use Vusys\Exocomp\Tests\Support\Cli;
use Vusys\Exocomp\Tests\Support\Run;
use Vusys\Exocomp\Tests\Support\TemporaryProject;

/**
 * Blade views are searched: components by their tag, and the PHP a view holds, read by syntax
 * and never compiled. Nothing found in a view is stronger than inferred.
 */
final class BladeViewsTest extends TestCase
{
    private TemporaryProject $project;

    protected function setUp(): void
    {
        $this->project = TemporaryProject::copyOfLaravel();
        $this->project->write('app/View/Components/OrderBadge.php', "<?php\nnamespace App\\View\\Components;\nclass OrderBadge {}\n");
        $this->project->write('resources/views/orders/show.blade.php', <<<'BLADE'
            @inject('writer', 'App\Services\CsvReportWriter')
            {{-- $writer->write('in a comment') --}}
            <h1>{{ $writer->write('report.csv') }}</h1>
            @foreach (\App\Models\Invoice::all() as $invoice)
                {!! $invoice->send() !!}
            @endforeach
            <x-order-badge
                :order="$order" />
            @{{ $writer->write('escaped') }}
            BLADE);
    }

    protected function tearDown(): void
    {
        $this->project->destroy();
    }

    public function test_a_component_used_only_in_a_view_is_referenced(): void
    {
        $run = $this->exocomp(['references-to', 'App\View\Components\OrderBadge']);

        $this->assertSame(0, $run->exitCode, $run->output);
        $this->assertStringContainsString('resources/views/orders/show.blade.php:7', $run->output);
        $this->assertStringContainsString('(0 resolved, 1 inferred', $run->output);
    }

    public function test_the_php_in_a_view_is_searched_with_its_types(): void
    {
        $writes = $this->exocomp(['callers-of', 'App\Services\CsvReportWriter::write'])->output;

        // Typed by `@inject`; neither the comment nor the escaped echo counts.
        $this->assertStringContainsString('resources/views/orders/show.blade.php:3', $writes);
        $this->assertStringNotContainsString('show.blade.php:2', $writes);
        $this->assertStringNotContainsString('show.blade.php:9', $writes);
        $this->assertStringContainsString('resources/views/orders/show.blade.php:5', $this->exocomp(['callers-of', 'App\Models\Invoice::send'])->output);
    }

    public function test_the_answer_says_views_were_searched(): void
    {
        $this->assertStringContainsString('Blade view was searched too', $this->exocomp(['references-to', 'App\Services\CsvReportWriter'])->output);
    }

    public function test_a_view_keeps_its_lines(): void
    {
        $blade = "<p>\n{{ \$a->b() }}\n</p>\n@if (\$x)\n{{ \$c->d() }}\n@endif\n";

        $this->assertSame(substr_count($blade, "\n"), substr_count(new BladeSource()->toPhp($blade), "\n"));
    }

    /**
     * @param  list<string>  $arguments
     */
    private function exocomp(array $arguments): Run
    {
        return Cli::run([...$arguments, '--project', $this->project->root]);
    }
}

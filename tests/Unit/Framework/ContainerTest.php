<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Framework;

use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Tests\Support\Cli;

/**
 * Tier E — the container: bindings, facades and the morph map.
 *
 * These are the edges a language-only call graph is missing. Every one of them is decided in a
 * file the call site never mentions, which is exactly why following them by hand was worth 700
 * measured searches — and why guessing at them would be worse than declining.
 */
final class ContainerTest extends TestCase
{
    public function test_an_interface_binding_names_its_concrete_class(): void
    {
        $run = Cli::inLaravel(['bindings']);

        $this->assertSame(0, $run->exitCode);
        $this->assertStringContainsString('App\Contracts\ReportWriter  →  App\Services\CsvReportWriter', $run->output);
    }

    public function test_a_closure_returning_one_new_is_followed_exactly_one_step(): void
    {
        $this->assertStringContainsString(
            'reporting  →  App\Services\ReportBuilder',
            Cli::inLaravel(['bindings'])->output,
        );
    }

    public function test_a_factory_that_builds_more_than_one_class_is_declined(): void
    {
        $output = Cli::inLaravel(['bindings'])->output;

        $this->assertStringContainsString('report.writer.chosen', $output);
        $this->assertStringContainsString('more than construct one class', $output);
    }

    public function test_a_contextual_binding_says_who_it_applies_to(): void
    {
        $this->assertStringContainsString(
            'when resolved for App\Http\Controllers\OrderController',
            Cli::inLaravel(['bindings'])->output,
        );
    }

    public function test_bindings_can_be_filtered(): void
    {
        $output = Cli::inLaravel(['bindings', 'ReportWriter'])->output;

        $this->assertStringContainsString('ReportWriter', $output);
        $this->assertStringNotContainsString('report.builder', $output);
    }

    public function test_a_facade_resolves_through_the_container_to_a_class(): void
    {
        $run = Cli::inLaravel(['facade', 'Reporting']);

        $this->assertSame(0, $run->exitCode);
        $this->assertStringContainsString('App\Services\ReportBuilder', $run->output);
        $this->assertStringContainsString('bound to "reporting"', $run->output);
    }

    public function test_a_facade_that_names_its_class_is_resolved_rather_than_inferred(): void
    {
        $run = Cli::inLaravel(['facade', 'Direct', '--json']);
        $first = json_decode((string) strtok($run->output, "\n"), true);

        $this->assertIsArray($first);
        $this->assertSame('resolved', $first['confidence']);
    }

    public function test_a_facade_reached_through_the_container_is_inferred(): void
    {
        $run = Cli::inLaravel(['facade', 'Reporting', '--json']);
        $first = json_decode((string) strtok($run->output, "\n"), true);

        $this->assertIsArray($first);
        $this->assertSame('inferred', $first['confidence'], 'a container hop is not a declaration');
    }

    public function test_a_facade_method_is_placed_on_the_concrete_class(): void
    {
        $output = Cli::inLaravel(['facade', 'Direct::build'])->output;

        $this->assertStringContainsString('app/Services/ReportBuilder.php', $output);
        $this->assertStringContainsString('ReportBuilder::build()', $output);
    }

    public function test_a_method_the_concrete_class_does_not_declare_is_reported_as_such(): void
    {
        $output = Cli::inLaravel(['facade', 'Direct::nowhere'])->output;

        $this->assertStringContainsString('not resolved', $output);
        $this->assertStringContainsString('forwarded, macroed, or handled by __call', $output);
    }

    public function test_a_method_the_facade_itself_declares_is_found_on_the_facade(): void
    {
        $output = Cli::inLaravel(['facade', 'Direct::fake'])->output;

        $this->assertStringContainsString('app/Facades/Direct.php', $output);
        $this->assertStringNotContainsString('forwarded, macroed', $output);
    }

    public function test_something_that_is_not_a_facade_is_refused(): void
    {
        $run = Cli::inLaravel(['facade', 'Order']);

        $this->assertSame(1, $run->exitCode);
        $this->assertStringContainsString('is not a facade', $run->errors);
    }

    public function test_the_morph_map_is_followed_into_the_config_file_it_lives_in(): void
    {
        // `Relation::morphMap(config('morph-map'))`. Declining this would leave the map empty on
        // exactly the applications with enough polymorphic relations to need one.
        $run = Cli::inLaravel(['morph-map']);

        $this->assertSame(0, $run->exitCode);
        $this->assertStringContainsString('order', $run->output);
        $this->assertStringContainsString('App\Models\Order', $run->output);
        $this->assertStringContainsString('config/morph-map.php', $run->output);
    }

    public function test_the_morph_map_says_that_its_keys_are_in_the_database(): void
    {
        $this->assertStringContainsString(
            'stored in the database',
            Cli::inLaravel(['morph-map'])->output,
        );
    }

    public function test_the_morph_map_can_be_looked_up_by_class(): void
    {
        $output = Cli::inLaravel(['morph-map', 'App\Models\Comment'])->output;

        $this->assertStringContainsString('comment', $output);
        $this->assertStringNotContainsString('App\Models\Order', $output);
    }
}

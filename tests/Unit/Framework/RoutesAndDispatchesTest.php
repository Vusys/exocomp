<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Framework;

use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Tests\Support\Cli;

/**
 * Tier E — the route table and the dispatch edges.
 *
 * Both are things `php artisan` answers better, when the application boots. In a disposable
 * worktree with no `.env` and no database it does not, which is exactly when someone is
 * spelunking an unfamiliar branch and needs the answer.
 */
final class RoutesAndDispatchesTest extends TestCase
{
    public function test_a_uri_is_the_concatenation_of_every_enclosing_prefix(): void
    {
        $run = Cli::inLaravel(['routes']);

        $this->assertSame(0, $run->exitCode);
        $this->assertStringContainsString('/admin/reports/monthly', $run->output);
    }

    public function test_group_names_and_middleware_accumulate(): void
    {
        $output = Cli::inLaravel(['routes', 'monthly', '--middleware', '--json'])->output;
        $record = json_decode((string) strtok($output, "\n"), true);

        $this->assertIsArray($record);
        $this->assertSame('admin.reports.monthly', $record['name']);
        $this->assertIsString($record['middleware']);
        $this->assertStringContainsString('auth', $record['middleware']);
    }

    public function test_a_resource_route_expands_to_the_routes_the_framework_generates(): void
    {
        // The source lists one line. Showing only that line would hide the endpoints.
        $output = Cli::inLaravel(['routes', '/api/orders'])->output;

        $this->assertStringContainsString('/api/orders/{order}', $output);
        $this->assertStringContainsString('routes/api.php:9', $output);
    }

    public function test_only_narrows_a_resource_to_the_actions_it_registers(): void
    {
        $output = Cli::inLaravel(['routes', 'api'])->output;

        $this->assertStringNotContainsString('/api/orders/create', $output);
    }

    public function test_the_options_array_form_is_read(): void
    {
        // `Route::get($uri, ['uses' => …, 'as' => …])`. Reading it as a callable pair finds
        // nothing, and a whole route file's endpoints go missing.
        $this->assertStringContainsString('HealthController', Cli::inLaravel(['routes', 'health'])->output);
    }

    public function test_a_bare_controller_inside_a_namespaced_group_is_qualified(): void
    {
        $this->assertStringContainsString('Legacy\OrderListController', Cli::inLaravel(['routes', 'legacy'])->output);
    }

    public function test_a_closure_route_says_there_is_no_controller(): void
    {
        $this->assertStringContainsString(
            'answered by a closure',
            Cli::inLaravel(['routes', 'home'])->output,
        );
    }

    public function test_routes_come_from_the_table_laravel_builds(): void
    {
        $this->assertStringContainsString('routes read from exocomp-routes.json', Cli::inLaravel(['routes'])->output);
    }

    public function test_a_job_is_found_at_both_dispatch_spellings(): void
    {
        $output = Cli::inLaravel(['dispatches', 'ProcessOrder'])->output;

        $this->assertStringContainsString('dispatch()', $output);
        $this->assertStringContainsString('ProcessOrder::dispatch()', $output);
    }

    public function test_a_job_handles_itself(): void
    {
        $this->assertStringContainsString(
            'the job handles itself',
            Cli::inLaravel(['dispatches', 'ProcessOrder'])->output,
        );
    }

    public function test_a_registered_listener_is_resolved_and_a_discovered_one_is_inferred(): void
    {
        $run = Cli::inLaravel(['dispatches', 'OrderPlaced', '--json']);
        $confidences = [];

        foreach (explode("\n", trim($run->output)) as $line) {
            $record = json_decode($line, true);

            if (is_array($record) && ($record['kind'] ?? null) === 'listener' && is_string($record['fqn'] ?? null)) {
                $confidences[$record['fqn']] = $record['confidence'];
            }
        }

        $this->assertSame('resolved', $confidences['App\Listeners\NotifyCustomer'] ?? null);
        $this->assertSame('inferred', $confidences['App\Listeners\RecordMetrics'] ?? null);
    }

    public function test_a_listener_registered_and_discoverable_is_reported_once(): void
    {
        $output = Cli::inLaravel(['dispatches', 'OrderPlaced'])->output;

        $this->assertSame(1, substr_count($output, 'App\Listeners\NotifyCustomer'), 'one listener, not two');
    }

    public function test_something_nothing_dispatches_is_not_found(): void
    {
        $run = Cli::inLaravel(['dispatches', 'Customer']);

        $this->assertSame(1, $run->exitCode);
        $this->assertStringContainsString('never dispatched', $run->errors);
    }
}

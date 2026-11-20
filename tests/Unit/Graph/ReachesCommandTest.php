<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Graph;

use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Tests\Support\Cli;

/**
 * `reaches` and `graph` through the command line, where the contract lives.
 *
 * The exit code is the part of this that other things depend on: `reaches … && …` is how an
 * audit gets written, and a negative answer that exits 0 because it still printed something
 * would break every one of them silently.
 */
final class ReachesCommandTest extends TestCase
{
    public function test_a_path_across_a_dispatch_is_found_and_exits_zero(): void
    {
        $run = Cli::inLaravel(['reaches', 'App\Services\OrderService::place', 'App\Models\Order::save']);

        $this->assertSame(0, $run->exitCode);
        $this->assertStringContainsString('reaches App\Models\Order::save in 2 steps', $run->output);
        $this->assertStringContainsString('dispatch  →  App\Jobs\ProcessOrder::handle', $run->output);
    }

    public function test_a_negative_answer_goes_to_standard_output_and_exits_one(): void
    {
        $run = Cli::inLaravel(['reaches', 'App\Services\ReportBuilder::build', 'App\Models\Order::save']);

        $this->assertSame(1, $run->exitCode);
        $this->assertStringContainsString('no path from', $run->output);
        $this->assertSame('', $run->errors);
    }

    public function test_a_negative_answer_says_whether_the_search_finished(): void
    {
        $complete = Cli::inLaravel(['reaches', 'App\Services\ReportBuilder::build', 'App\Models\Order::save']);
        $this->assertStringContainsString('ran out of graph rather than out of budget', $complete->output);

        $curtailed = Cli::inLaravel(['reaches', 'App\Services\OrderService::place', 'App\Models\Order::save', '--depth', '1']);
        $this->assertSame(1, $curtailed->exitCode);
        $this->assertStringContainsString('within the depth and node budget', $curtailed->output);
        $this->assertStringContainsString('raise --depth', $curtailed->output);
    }

    public function test_the_weakest_step_is_named_rather_than_the_strongest(): void
    {
        $run = Cli::inLaravel(['reaches', 'App\Services\ReportDispatcher::run', 'App\Services\ReportBuilder::build']);

        $this->assertSame(0, $run->exitCode);
        $this->assertStringContainsString('the weakest step is inferred', $run->output);
    }

    public function test_a_route_sweep_answers_every_endpoint_in_one_call(): void
    {
        $run = Cli::inLaravel(['reaches', 'route:*', 'App\Models\Order::save']);

        $this->assertSame(0, $run->exitCode);
        $this->assertStringContainsString('of 8 starting points reach', $run->output);
        $this->assertStringContainsString('POST /orders', $run->output);
        $this->assertStringContainsString('reached in 3 steps', $run->output);
        $this->assertStringContainsString('not reached', $run->output);
    }

    public function test_a_route_start_names_the_declaration_the_walk_began_at(): void
    {
        $run = Cli::inLaravel(['reaches', 'route:POST /orders', 'App\Models\Order::save']);

        $this->assertSame(0, $run->exitCode);
        $this->assertStringContainsString('POST /orders (App\Http\Controllers\OrderController::store) reaches', $run->output);
    }

    public function test_a_symbol_start_is_not_annotated_with_itself(): void
    {
        $output = Cli::inLaravel(['reaches', 'App\Services\OrderService::place', 'App\Models\Order::save'])->output;

        $this->assertStringNotContainsString('(App\Services\OrderService::place)', $output);
    }

    public function test_a_route_answered_by_a_closure_is_reported_rather_than_dropped(): void
    {
        $output = Cli::inLaravel(['reaches', 'route:*', 'App\Models\Order::save'])->output;

        $this->assertStringContainsString('answered by a closure rather than a controller', $output);
    }

    public function test_a_sweep_that_reaches_nothing_exits_one(): void
    {
        $run = Cli::inLaravel(['reaches', 'route:*', '::neverCalledAnywhere']);

        $this->assertSame(1, $run->exitCode);
        $this->assertStringContainsString('0 of 8 starting points', $run->output);
    }

    public function test_a_route_pattern_matching_nothing_is_a_not_found(): void
    {
        $run = Cli::inLaravel(['reaches', 'route:/nowhere-at-all', 'App\Models\Order::save']);

        $this->assertSame(1, $run->exitCode);
        $this->assertStringContainsString('none matches', $run->errors);
    }

    public function test_json_leads_with_the_verdict_when_the_answer_is_negative(): void
    {
        $run = Cli::inLaravel(['reaches', 'App\Services\ReportBuilder::build', 'App\Models\Order::save', '--json']);
        $first = json_decode((string) strtok($run->output, "\n"), true);

        $this->assertIsArray($first);
        $this->assertFalse($first['found']);
        $this->assertSame(1, $run->exitCode);
    }

    public function test_json_records_carry_the_step_each_edge_came_from(): void
    {
        $output = Cli::inLaravel(['reaches', 'App\Services\OrderService::place', 'App\Models\Order::save', '--json'])->output;
        $first = json_decode((string) strtok($output, "\n"), true);

        $this->assertIsArray($first);
        $this->assertSame('App\Services\OrderService::place', $first['from']);
        $this->assertSame('App\Jobs\ProcessOrder::handle', $first['fqn']);
        $this->assertSame('dispatch', $first['kind']);
        $this->assertSame('resolved', $first['confidence']);
    }

    public function test_a_sweep_record_carries_the_whole_verdict_and_not_only_its_wording(): void
    {
        $output = Cli::inLaravel(['reaches', 'route:*', 'App\Models\Order::save', '--json'])->output;
        $records = $this->records($output);
        $reached = $this->recordWhere($records, 'kind', 'reached');

        $this->assertSame('routes/web.php', $reached['file']);
        $this->assertSame(18, $reached['line']);
        $this->assertSame(1, $reached['col']);
        $this->assertSame('App\Http\Controllers\OrderController::store', $reached['fqn']);
        $this->assertSame('resolved', $reached['confidence']);
        $this->assertSame(3, $reached['steps']);
        $this->assertSame('routes/web.php:18:1  POST /orders                reached in 3 steps', $reached['text']);
    }

    public function test_a_starting_point_that_was_not_reached_says_so_in_every_field(): void
    {
        $records = $this->records(Cli::inLaravel(['reaches', 'route:*', 'App\Models\Order::save', '--json'])->output);
        $missed = $this->recordWhere($records, 'fqn', 'App\Http\Controllers\HealthController::__invoke');

        $this->assertSame('not reached', $missed['kind']);
        $this->assertSame('textual', $missed['confidence']);
        $this->assertSame(0, $missed['steps']);
    }

    public function test_a_stop_record_names_where_the_walk_was_and_why_it_stopped(): void
    {
        $records = $this->records(Cli::inLaravel(['reaches', 'App\Services\ReportDispatcher::queue', '::neverCalledAnywhere', '--json'])->output);
        $stop = $this->recordWhere($records, 'kind', 'unresolved');

        $this->assertSame('app/Services/ReportDispatcher.php', $stop['file']);
        $this->assertSame(48, $stop['line']);
        $this->assertSame(35, $stop['col']);
        $this->assertSame('App\Services\ReportDispatcher::queue', $stop['from']);
        $this->assertSame('receiver type unknown', $stop['reason']);
        $this->assertTrue($stop['unresolved']);
    }

    public function test_a_dependency_stop_names_the_class_that_declares_the_method(): void
    {
        $records = $this->records(Cli::inLaravel(['reaches', 'App\Services\ReportDispatcher::queue', '::neverCalledAnywhere', '--json'])->output);
        $stop = $this->recordWhere($records, 'kind', 'dependency');

        // The address asked for and the declaration found are both reported, because they are
        // different facts: the walk was at the model, the body is on the framework base class.
        $this->assertSame('App\Models\Order::save', $stop['from']);
        $this->assertStringContainsString('Illuminate\Database\Eloquent\Model::save', $this->text($stop, 'text'));
        $this->assertStringStartsWith('vendor/', $this->text($stop, 'file'));
    }

    public function test_one_of_something_is_said_as_one_rather_than_as_a_plural(): void
    {
        $output = Cli::inLaravel(['reaches', 'App\Services\ReportDispatcher::queue', '::neverCalledAnywhere'])->output;

        $this->assertStringContainsString('one edge led into a dependency and was not walked past', $output);
        $this->assertStringNotContainsString('1 edge led', $output);
    }

    public function test_a_destination_matching_any_class_is_accepted(): void
    {
        $run = Cli::inLaravel(['reaches', 'App\Services\OrderService::place', '::save']);

        $this->assertSame(0, $run->exitCode);
    }

    public function test_the_neighbourhood_is_grouped_by_how_far_out_each_step_is(): void
    {
        $run = Cli::inLaravel(['graph', 'App\Services\OrderService::place', '--depth', '2']);

        $this->assertSame(0, $run->exitCode);
        $this->assertStringContainsString('1 step out', $run->output);
        $this->assertStringContainsString('2 steps out', $run->output);
        $this->assertStringContainsString('listens  →  App\Listeners\NotifyCustomer::handle', $run->output);
    }

    public function test_the_neighbourhood_reports_the_edges_it_could_not_follow(): void
    {
        $output = Cli::inLaravel(['graph', 'App\Services\OrderService::place', '--depth', '3'])->output;

        $this->assertStringContainsString('not followed', $output);
        $this->assertStringContainsString('--through-vendor', $output);
    }

    public function test_a_symbol_with_no_readable_declaration_is_a_not_found(): void
    {
        $run = Cli::inLaravel(['graph', 'App\Nothing\AtAll::ever']);

        $this->assertSame(1, $run->exitCode);
        $this->assertStringContainsString('no declaration', $run->errors);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function text(array $record, string $field): string
    {
        $value = $record[$field] ?? null;

        $this->assertIsString($value, "{$field} is not a string");

        return $value;
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return array<string, mixed>
     */
    private function recordWhere(array $records, string $field, string $value): array
    {
        foreach ($records as $record) {
            if (($record[$field] ?? null) === $value) {
                return $record;
            }
        }

        $this->fail("no record with {$field} = {$value}");
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function records(string $output): array
    {
        $records = [];

        foreach (explode("\n", trim($output)) as $line) {
            $decoded = json_decode($line, true);

            if (! is_array($decoded)) {
                continue;
            }

            /** @var array<string, mixed> $record */
            $record = $decoded;
            $records[] = $record;
        }

        return $records;
    }

    public function test_limit_caps_the_neighbourhood_like_every_other_command(): void
    {
        $output = Cli::inLaravel(['graph', 'App\Services\OrderService::place', '--depth', '2', '--limit', '3'])->output;

        $this->assertStringContainsString('more (use --limit)', $output);
    }
}

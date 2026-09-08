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

    public function test_a_route_answered_by_a_closure_is_reported_rather_than_dropped(): void
    {
        $output = Cli::inLaravel(['reaches', 'route:*', 'App\Models\Order::save'])->output;

        $this->assertStringContainsString('answered by a closure rather than a controller', $output);
    }

    public function test_a_sweep_that_reaches_nothing_exits_one(): void
    {
        $run = Cli::inLaravel(['reaches', 'route:*', 'Nothing\AtAll::ever']);

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

    public function test_limit_caps_the_neighbourhood_like_every_other_command(): void
    {
        $output = Cli::inLaravel(['graph', 'App\Services\OrderService::place', '--depth', '2', '--limit', '3'])->output;

        $this->assertStringContainsString('more (use --limit)', $output);
    }
}

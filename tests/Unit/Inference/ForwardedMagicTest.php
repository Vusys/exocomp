<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Inference;

use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Tests\Support\Cli;

/**
 * One rule for every kind of magic: a call is addressed as reached through its receiver, and
 * carries the declaration it lands on. `calls-from`, `callers-of`, `show` and `reaches` all
 * answer from the same two facts.
 */
final class ForwardedMagicTest extends TestCase
{
    public function test_calls_from_names_the_address_and_where_it_lands(): void
    {
        $output = Cli::inLaravel(['calls-from', 'App\Inference\EloquentProbes::forwarded'])->output;

        $this->assertStringContainsString('App\Inference\Shipment::find  → Illuminate\Database\Eloquent\Builder::find', $output);
    }

    public function test_callers_of_the_landing_declaration_finds_the_forwarded_call(): void
    {
        $output = Cli::inLaravel(['callers-of', 'Illuminate\Database\Eloquent\Builder::find', '--max-tokens', '0'])->output;

        $this->assertStringContainsString('App\Inference\EloquentProbes::forwarded', $output);
    }

    public function test_show_prints_where_a_forwarded_member_lands(): void
    {
        $run = Cli::inLaravel(['show', 'App\Inference\Shipment::find']);

        $this->assertSame(0, $run->exitCode, $run->errors);
        $this->assertStringContainsString('Illuminate\Database\Eloquent\Builder::find', $run->output);
        $this->assertStringContainsString('lands on', $run->output);
    }

    public function test_reaches_arrives_at_either_name(): void
    {
        $this->assertSame(0, Cli::inLaravel(['reaches', 'App\Inference\EloquentProbes::forwarded', 'App\Inference\Shipment::find'])->exitCode);
        $this->assertSame(0, Cli::inLaravel(['reaches', 'App\Inference\EloquentProbes::forwarded', 'Illuminate\Database\Eloquent\Builder::find'])->exitCode);
    }
}

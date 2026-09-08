<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Tests\Support\Cli;

/**
 * Exit codes are part of the interface: 0 found, 1 not found, 2 error.
 *
 * The distinction earns its keep in `&&` chains and CI guards. Collapsing "this symbol does
 * not exist" and "you invoked me wrongly" into one non-zero code would make both meaningless.
 */
final class ExitCodesTest extends TestCase
{
    public function test_a_found_symbol_exits_zero(): void
    {
        $this->assertSame(0, Cli::inCorpus(['show', 'Fixtures\TierA\Baseline::greet'])->exitCode);
    }

    public function test_a_missing_class_exits_one(): void
    {
        $run = Cli::inCorpus(['show', 'Fixtures\TierA\NoSuchClass']);

        $this->assertSame(1, $run->exitCode);
        $this->assertSame('', $run->output);
        $this->assertStringContainsString('no declaration of "Fixtures\TierA\NoSuchClass"', $run->errors);
    }

    public function test_a_missing_member_exits_one_and_suggests_where_to_look_next(): void
    {
        $run = Cli::inCorpus(['show', 'Fixtures\TierA\Baseline::describe']);

        $this->assertSame(1, $run->exitCode);
        $this->assertStringContainsString('declares no member "describe"', $run->errors);

        // `describe()` really is callable on Baseline — it is inherited. Saying "no" without
        // saying "it may be inherited, here is the command" would be true and useless.
        $this->assertStringContainsString('exocomp members Fixtures\TierA\Baseline', $run->errors);
    }

    public function test_a_malformed_address_exits_two(): void
    {
        $run = Cli::inCorpus(['show', 'App\Order::a::b']);

        $this->assertSame(2, $run->exitCode);
        $this->assertStringContainsString('more than one "::"', $run->errors);
    }

    public function test_an_unknown_format_exits_two(): void
    {
        $run = Cli::inCorpus(['outline', 'src/TierA/Status.php', '--format', 'yaml']);

        $this->assertSame(2, $run->exitCode);
        $this->assertStringContainsString('unknown --format "yaml"', $run->errors);
    }

    public function test_an_unparseable_file_exits_two_and_names_the_line(): void
    {
        $run = Cli::inCorpus(['outline', 'src/TierF/Unparseable.php']);

        $this->assertSame(2, $run->exitCode);
        $this->assertStringContainsString('src/TierF/Unparseable.php:15', $run->errors);
    }

    public function test_diagnostics_never_reach_stdout(): void
    {
        $run = Cli::inCorpus(['members', 'Fixtures\Nope']);

        $this->assertSame('', $run->output);
        $this->assertNotSame('', $run->errors);
    }
}

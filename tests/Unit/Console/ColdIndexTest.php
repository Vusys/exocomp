<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Tests\Support\Cli;
use Vusys\Exocomp\Tests\Unit\Project\DeclarationScanTest;

/**
 * A negative that has actually been checked.
 *
 * `locate` was the one command with nothing to fall through to. Every other command tries
 * Composer's map when a name misses; this one asked the index and reported whatever it said, so
 * an index part-way through its first pass over a fresh worktree produced "nothing named
 * Gateway is declared" while `show` read `App\Models\Billing\Gateway` out of the same
 * checkout seconds later. Eight such false negatives were recorded in a single morning, all of
 * them on worktrees created that day, none after the index finished building.
 *
 * A confident wrong "no" is worse than the grep it replaced, and it landed in the command the
 * guidance names as the way out of a wrong guess. So the bytes are read before any negative is
 * returned — {@see DeclarationScanTest} covers the reading itself — and the negative says what
 * stood behind it.
 */
final class ColdIndexTest extends TestCase
{
    public function test_a_real_negative_says_how_big_the_index_was(): void
    {
        // "Nothing named X" out of an index of 27 files is a fact; the same sentence out of an
        // index of nothing is an accident, and the two used to read identically.
        $run = Cli::inCorpus(['locate', 'NothingIsCalledThisAnywhere']);

        $this->assertSame(1, $run->exitCode);
        $this->assertMatchesRegularExpression('/index: \d+ files, \d+ symbols/', $run->errors);
    }

    public function test_a_real_negative_says_the_source_was_read(): void
    {
        $run = Cli::inCorpus(['locate', 'NothingIsCalledThisAnywhere']);

        $this->assertStringContainsString('this is not a stale answer', $run->errors);
    }

    public function test_no_sync_declines_to_read_the_source_and_says_so(): void
    {
        // The escape hatch for a caller who wants speed and has accepted the risk. It must
        // never be able to claim the stronger guarantee it did not pay for.
        $run = Cli::inCorpus(['locate', 'NothingIsCalledThisAnywhere', '--no-sync']);

        $this->assertSame(1, $run->exitCode);
        $this->assertStringContainsString('--no-sync was given', $run->errors);
        $this->assertStringNotContainsString('not a stale answer', $run->errors);
    }

    public function test_several_missing_names_are_reported_together(): void
    {
        $run = Cli::inCorpus(['locate', 'NothingIsCalledThis', 'NorIsThis']);

        $this->assertSame(1, $run->exitCode);
        $this->assertStringContainsString('"NothingIsCalledThis" or "NorIsThis"', $run->errors);
    }
}

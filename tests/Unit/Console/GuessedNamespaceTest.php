<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Tests\Support\Cli;
use Vusys\Exocomp\Tests\Support\Run;

/**
 * The commonest way a name fails to resolve, taken from real sessions.
 *
 * An agent knows a class is called `Repository` and guesses the namespace it lives under. The
 * answer "no declaration of that" is correct and useless: the index already holds the real
 * name, and the advice being given instead — pass `--scan` — cannot place a class that is not
 * there under the name asked for, so it costs a second run and returns the same nothing.
 */
final class GuessedNamespaceTest extends TestCase
{
    public function test_a_guessed_namespace_is_answered_with_the_real_one(): void
    {
        $run = $this->miss('Fixtures\Wrong\Place\Repository');

        $this->assertSame(1, $run->exitCode);
        $this->assertStringContainsString('"Repository" is declared as Fixtures\TierA\Support\Repository', $run->errors);
    }

    public function test_a_name_that_exists_nowhere_still_gets_the_scan_advice(): void
    {
        // In the installed fixture, which has a dumped classmap and so declines the content
        // scan rather than running it unasked — the shape a real vendored project has.
        $run = $this->installed('Wrong\Ns\NotAnythingAtAll');

        $this->assertStringContainsString('pass --scan', $run->errors);
        $this->assertStringNotContainsString('is declared as', $run->errors);
    }

    public function test_the_scan_advice_is_withheld_where_it_would_otherwise_be_given(): void
    {
        $run = $this->installed('Wrong\Ns\Placed');

        $this->assertStringContainsString('"Placed" is declared as Installed\Placed', $run->errors);
        $this->assertStringNotContainsString('pass --scan', $run->errors);
    }

    public function test_a_bare_short_name_is_not_offered_back_to_itself(): void
    {
        // No namespace was guessed, so there is nothing to correct — the short name is the
        // question that was just answered.
        $this->assertStringNotContainsString('is declared as', $this->miss('NoSuchThingAnywhere')->errors);
    }

    private function installed(string $symbol): Run
    {
        $root = dirname(__DIR__, 2).'/Fixtures/installed';

        Cli::run(['index', '--warm', '--project', $root]);

        return Cli::run(['show', $symbol, '--project', $root]);
    }

    private function miss(string $symbol): Run
    {
        // Warmed first so the lookup has an index to read. It is never built for this: a
        // command that does not need an index must not acquire one to improve an error.
        Cli::inCorpus(['index', '--warm']);

        return Cli::inCorpus(['show', $symbol]);
    }
}

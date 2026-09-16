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
 *
 * Measured across ten days of transcripts, seven of every ten `show` and `members` failures
 * were this, and five of those printed the right name as a hint and then made the caller ask
 * again for it. So the hint became an answer — but only where there is exactly one name it
 * could be, because a short name carried by two classes is a real ambiguity and choosing
 * between them would be the guess this engine refuses to make.
 */
final class GuessedNamespaceTest extends TestCase
{
    public function test_a_guessed_namespace_is_answered_with_the_real_one(): void
    {
        $run = $this->miss('Fixtures\Wrong\Place\Repository');

        $this->assertSame(0, $run->exitCode);
        $this->assertStringContainsString('src/TierA/Support/Repository.php:', $run->output);
    }

    public function test_the_substitution_is_never_silent(): void
    {
        // An answer about a different class than the one asked for is only safe while it is
        // impossible to mistake for an answer about the one asked for.
        $run = $this->miss('Fixtures\Wrong\Place\Repository');

        $this->assertStringContainsString(
            'nothing is declared as "Fixtures\Wrong\Place\Repository" — answered Fixtures\TierA\Support\Repository instead',
            $run->output,
        );
    }

    public function test_a_bare_short_name_resolves_on_its_own(): void
    {
        // The whole address, with no namespace at all. There is one Repository, so there is
        // nothing to choose between and no reason to make the caller spell it out.
        $run = $this->miss('Repository::find');

        $this->assertSame(0, $run->exitCode);
        $this->assertStringContainsString('src/TierA/Support/Repository.php:', $run->output);
    }

    public function test_an_ambiguous_short_name_is_not_corrected(): void
    {
        // Two classes are called Alpha. Picking either would be a guess, so the miss stands and
        // the candidates are named instead.
        $run = $this->miss('Fixtures\Wrong\Place\Alpha');

        $this->assertSame(1, $run->exitCode);
        $this->assertStringContainsString('"Alpha" is declared as', $run->errors);
        $this->assertStringContainsString('Fixtures\TierB\Deep\Alpha', $run->errors);
    }

    public function test_a_name_that_exists_nowhere_still_gets_the_scan_advice(): void
    {
        // In the installed fixture, which has a dumped classmap and so declines the content
        // scan rather than running it unasked — the shape a real vendored project has.
        $run = $this->installed('Wrong\Ns\NotAnythingAtAll');

        $this->assertSame(1, $run->exitCode);
        $this->assertStringContainsString('pass --scan', $run->errors);
        $this->assertStringNotContainsString('is declared as', $run->errors);
    }

    public function test_a_guessed_namespace_is_answered_in_an_installed_project_too(): void
    {
        $run = $this->installed('Wrong\Ns\Placed');

        $this->assertSame(0, $run->exitCode);
        $this->assertStringContainsString('nothing is declared as "Wrong\Ns\Placed" — answered Installed\Placed instead', $run->output);
        $this->assertStringNotContainsString('pass --scan', $run->output);
    }

    public function test_a_speculative_lookup_is_never_corrected(): void
    {
        // Walking a hierarchy asks about ancestors that are legitimately absent. Substituting a
        // same-named class from elsewhere would fabricate a parent that is not there, so the
        // correction is withheld on any lookup the caller did not type.
        $run = Cli::inCorpus(['members', 'Fixtures\TierA\Support\Repository']);

        $this->assertSame(0, $run->exitCode);
        $this->assertStringNotContainsString('answered', $run->output);
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

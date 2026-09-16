<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Console\ExocompCommand;
use Vusys\Exocomp\Output\OutputBudget;
use Vusys\Exocomp\Tests\Support\Cli;

/**
 * A ceiling that cannot be forgotten.
 *
 * The measurement behind this is stark. Of 236 real invocations, 234 piped the answer into
 * `head -N` and 7 used `--limit` or `--max-tokens` — a ratio of 33 to 1 against an instruction
 * that is written down, explained, and had been restated three times. A third of those pipes
 * cut the answer, and `head` cuts silently, which destroys the single guarantee this tool makes
 * about its own output. One caller even proved it in passing: `exocomp locate X | head -40;
 * echo $?` printed 0 after a miss, because the exit status of a pipeline is `head`'s.
 *
 * Guidance was not going to fix that. A default that applies whenever output is not a terminal
 * needs nobody to remember anything, and is set high enough to bound a runaway rather than to
 * ration an ordinary answer.
 */
final class TokenCeilingTest extends TestCase
{
    public function test_the_ceiling_applies_when_output_is_not_a_terminal(): void
    {
        // The test harness writes to a buffer, which is exactly what an agent's pipe is.
        $budget = new OutputBudget(maxTokens: 10, implicit: true);

        $this->assertStringContainsString(
            'cut at the default ceiling of 10 tokens',
            $budget->capRendered(str_repeat("a line of output\n", 50)),
        );
    }

    public function test_a_cut_at_the_default_ceiling_says_how_to_lift_it(): void
    {
        $budget = new OutputBudget(maxTokens: 10, implicit: true);
        $rendered = $budget->capRendered(str_repeat("a line of output\n", 50));

        $this->assertStringContainsString('--max-tokens N to raise it, 0 to remove it', $rendered);
    }

    public function test_an_explicit_ceiling_keeps_its_own_wording(): void
    {
        // A caller who named the ceiling does not need telling where it came from.
        $budget = new OutputBudget(maxTokens: 10);
        $rendered = $budget->capRendered(str_repeat("a line of output\n", 50));

        $this->assertStringContainsString('(use --max-tokens)', $rendered);
        $this->assertStringNotContainsString('default ceiling', $rendered);
    }

    public function test_an_explicit_zero_removes_the_ceiling_entirely(): void
    {
        // `--max-tokens 0` is how someone asks for the whole answer, and it has to keep working
        // or the default would be a cap nobody can escape.
        $run = Cli::inCorpus(['members', 'Fixtures\TierA\Baseline', '--max-tokens', '0']);

        $this->assertSame(0, $run->exitCode);
        $this->assertStringNotContainsString('more lines', $run->output);
    }

    public function test_an_ordinary_answer_is_never_touched_by_the_default(): void
    {
        // The whole design depends on this: a ceiling that bit real answers would be a worse
        // problem than the one it solves.
        $run = Cli::inCorpus(['members', 'Fixtures\TierA\Baseline']);

        $this->assertSame(0, $run->exitCode);
        $this->assertStringNotContainsString('default ceiling', $run->output);
    }

    public function test_the_default_is_far_above_any_answer_measured(): void
    {
        // Median real answer 1KB, ninetieth percentile 5KB, largest 13KB — against roughly
        // 100KB here. If this ever needs lowering, the reason will not be these numbers.
        $this->assertGreaterThanOrEqual(20000, ExocompCommand::DEFAULT_MAX_TOKENS);
    }

    public function test_an_explicit_ceiling_still_bites_where_asked(): void
    {
        $run = Cli::inCorpus(['members', 'Fixtures\TierA\Baseline', '--max-tokens', '5']);

        $this->assertStringContainsString('more lines', $run->output);
    }
}

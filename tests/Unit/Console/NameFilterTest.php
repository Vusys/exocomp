<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Tests\Support\Cli;

/**
 * Narrowing an answer without piping it through `grep`.
 *
 * Both halves of this come from the same measurement. Callers piped this tool's output into
 * `grep` 25 times in ten days — `exocomp members X | grep -iE 'contract|countMessages'` — which
 * works, and quietly gives back the problem the output contract exists to solve: a filtered
 * list says nothing about what the filter removed. And they asked about several names at once
 * by OR-ing them into one grep, because asking three times cost three commands.
 */
final class NameFilterTest extends TestCase
{
    public function test_members_can_be_filtered_by_name(): void
    {
        $run = Cli::inCorpus(['members', 'Fixtures\TierA\Baseline', '--matching', 'greet']);

        $this->assertSame(0, $run->exitCode);
        $this->assertStringContainsString('greet', $run->output);
        $this->assertStringNotContainsString('repository', $run->output);
    }

    public function test_a_filtered_list_says_what_the_filter_removed(): void
    {
        // The whole point. "2 of 40" cannot be mistaken for a class with two members.
        $run = Cli::inCorpus(['members', 'Fixtures\TierA\Baseline', '--matching', 'greet']);

        $this->assertMatchesRegularExpression('/\(\d+ of \d+ members matching\)/', $run->output);
    }

    public function test_the_filter_is_case_insensitive_and_unanchored(): void
    {
        $run = Cli::inCorpus(['members', 'Fixtures\TierA\Baseline', '--matching', 'REE']);

        $this->assertSame(0, $run->exitCode);
        $this->assertStringContainsString('greet', $run->output);
    }

    public function test_an_alternation_is_the_shape_the_grep_pipe_had(): void
    {
        $run = Cli::inCorpus(['members', 'Fixtures\TierA\Baseline', '--matching', 'greet|repository']);

        $this->assertSame(0, $run->exitCode);
        $this->assertStringContainsString('greet', $run->output);
        $this->assertStringContainsString('repository', $run->output);
    }

    public function test_matching_nothing_is_not_found_rather_than_an_empty_success(): void
    {
        // `exocomp members X --matching y && …` must not proceed on no members at all.
        $run = Cli::inCorpus(['members', 'Fixtures\TierA\Baseline', '--matching', 'nothingIsCalledThis']);

        $this->assertSame(1, $run->exitCode);
    }

    public function test_an_invalid_expression_is_an_error_not_an_empty_answer(): void
    {
        // Silently returning nothing would read as "the class has no such member", which is a
        // different and wrong claim.
        $run = Cli::inCorpus(['members', 'Fixtures\TierA\Baseline', '--matching', '(unclosed']);

        $this->assertSame(2, $run->exitCode);
        $this->assertStringContainsString('is not a valid regular expression', $run->errors);
    }

    public function test_the_filter_applies_to_the_index_free_path_too(): void
    {
        $run = Cli::inCorpus(['members', 'Fixtures\TierA\Baseline', '--own', '--matching', 'greet']);

        $this->assertSame(0, $run->exitCode);
        $this->assertMatchesRegularExpression('/\(\d+ of \d+ own members matching\)/', $run->output);
    }

    public function test_several_names_are_located_in_one_command(): void
    {
        $run = Cli::inCorpus(['locate', 'Repository', 'Omega']);

        $this->assertSame(0, $run->exitCode);
        $this->assertStringContainsString('Fixtures\TierA\Support\Repository', $run->output);
        $this->assertStringContainsString('Fixtures\TierB\Omega', $run->output);
    }

    public function test_one_missing_name_among_several_does_not_withdraw_the_others(): void
    {
        $run = Cli::inCorpus(['locate', 'Repository', 'NothingIsCalledThis']);

        $this->assertSame(1, $run->exitCode);
        $this->assertStringContainsString('Fixtures\TierA\Support\Repository', $run->output);
        $this->assertStringContainsString('not declared anywhere', $run->output);
        $this->assertStringContainsString('NothingIsCalledThis', $run->output);
    }

    public function test_one_name_keeps_the_answer_it_has_always_had(): void
    {
        $run = Cli::inCorpus(['locate', 'Repository']);

        $this->assertSame(0, $run->exitCode);
        $this->assertStringContainsString('declarations of "Repository"', $run->output);
        $this->assertStringNotContainsString('across', $run->output);
    }
}

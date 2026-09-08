<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Output;

use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Output\OutputBudget;
use Vusys\Exocomp\Output\Row;

final class OutputBudgetTest extends TestCase
{
    public function test_no_limit_keeps_everything(): void
    {
        [$kept, $elided] = (new OutputBudget)->apply($this->rows(5));

        $this->assertCount(5, $kept);
        $this->assertSame(0, $elided);
    }

    public function test_a_limit_reports_exactly_what_it_dropped(): void
    {
        [$kept, $elided] = new OutputBudget(limit: 2)->apply($this->rows(5));

        $this->assertCount(2, $kept);

        // The count is the whole point. A cap that dropped rows without saying how many would
        // read as complete coverage, which is how an audit gets wrongly signed off.
        $this->assertSame(3, $elided);
    }

    public function test_a_limit_larger_than_the_result_drops_nothing(): void
    {
        [$kept, $elided] = new OutputBudget(limit: 99)->apply($this->rows(3));

        $this->assertCount(3, $kept);
        $this->assertSame(0, $elided);
    }

    public function test_output_under_the_token_ceiling_is_untouched(): void
    {
        $text = "one\ntwo\nthree";

        $this->assertSame($text, new OutputBudget(maxTokens: 1000)->capRendered($text));
    }

    public function test_output_over_the_token_ceiling_is_cut_on_a_line_boundary_and_says_so(): void
    {
        $text = implode("\n", array_fill(0, 50, str_repeat('x', 40)));

        $capped = new OutputBudget(maxTokens: 20)->capRendered($text);

        $this->assertStringContainsString('more lines (use --max-tokens)', $capped);
        $this->assertLessThan(substr_count($text, "\n"), substr_count($capped, "\n"));

        foreach (explode("\n", $capped) as $line) {
            $this->assertTrue($line === str_repeat('x', 40) || str_starts_with($line, '… '));
        }
    }

    public function test_the_token_estimate_is_four_characters_to_a_token(): void
    {
        $this->assertSame(0, OutputBudget::tokens(''));
        $this->assertSame(1, OutputBudget::tokens('abc'));
        $this->assertSame(1, OutputBudget::tokens('abcd'));
        $this->assertSame(2, OutputBudget::tokens('abcde'));
    }

    /**
     * @return list<Row>
     */
    private function rows(int $count): array
    {
        return array_map(
            static fn (int $index): Row => new Row('f.php', $index, 1, 'F', 'method', 'row '.$index),
            range(1, $count),
        );
    }
}

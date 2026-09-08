<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Inference;

use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Symbol\Confidence;
use Vusys\Exocomp\Tests\Support\Cli;

/**
 * "Method not found" is two statements and only one of them is about the code.
 *
 * A class whose ancestry runs off the end of the indexed source has an API far larger than its
 * own declarations. Reporting an undeclared call there the same way as a call to a method that
 * genuinely is not declared anywhere tells a reader their code is broken when what happened is
 * that the tool could not see far enough — and a reader who checks once and finds the tool was
 * wrong stops trusting the rest of the output.
 */
final class UnreachableMethodTest extends TestCase
{
    public function test_a_call_past_the_edge_of_the_index_says_so_rather_than_calling_it_missing(): void
    {
        $output = Cli::inLaravel(['calls-from', 'App\Services\OrderReport::scopedCount'])->output;

        $this->assertStringContainsString('ancestry leaves the indexed source at Illuminate\Database\Eloquent\Model', $output);
        $this->assertStringContainsString('forwarded by a magic __call', $output);
    }

    public function test_a_method_genuinely_absent_from_a_fully_readable_class_is_reported_plainly(): void
    {
        $output = Cli::inLaravel(['calls-from', 'App\Services\OrderReport::scopedCount', '--json'])->output;

        // The reason names the class and the method, and does not claim an ancestry gap that
        // is not there for a class the index has whole.
        $this->assertStringContainsString('declares no whereTenant()', $output);
    }

    public function test_the_weakest_step_decides_what_a_chain_is_worth(): void
    {
        $this->assertSame(Confidence::Resolved, Confidence::weakest(Confidence::Resolved, Confidence::Resolved));
        $this->assertSame(Confidence::Inferred, Confidence::weakest(Confidence::Resolved, Confidence::Inferred));
        $this->assertSame(Confidence::Textual, Confidence::weakest(Confidence::Inferred, Confidence::Textual));
        $this->assertSame(Confidence::Textual, Confidence::weakest(Confidence::Textual, Confidence::Resolved));
        $this->assertSame(Confidence::Resolved, Confidence::weakest());
    }
}

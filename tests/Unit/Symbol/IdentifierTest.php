<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Symbol;

use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Symbol\Identifier;
use Vusys\Exocomp\Tests\Support\Cli;

/**
 * The folding rule the whole index depends on, pinned in both directions.
 *
 * Getting it wrong in the permissive direction is the dangerous one: two classes PHP keeps
 * apart become one index row, and every later answer about either is drawn from whichever was
 * stored last — with full confidence, because nothing downstream can tell they were merged.
 */
final class IdentifierTest extends TestCase
{
    public function test_ascii_names_fold(): void
    {
        $this->assertSame('handle', Identifier::fold('HANDLE'));
        $this->assertSame('app\models\order', Identifier::fold('App\Models\Order'));
    }

    public function test_bytes_above_ascii_are_left_alone(): void
    {
        // `mb_strtolower` would return "étage" here and "i̇" for the dotted capital I, merging
        // names the engine treats as distinct.
        $this->assertSame("\u{00C9}tage", Identifier::fold("\u{00C9}tage"));
        $this->assertSame("\u{0130}", Identifier::fold("\u{0130}"));
    }

    public function test_the_index_keeps_two_names_that_differ_only_above_ascii_apart(): void
    {
        $upper = Cli::inCorpus(['locate', "Fixtures\\TierB\\\u{00C9}tage", '--json']);
        $lower = Cli::inCorpus(['locate', "Fixtures\\TierB\\\u{00E9}tage", '--json']);

        $this->assertSame(0, $upper->exitCode);
        $this->assertSame(0, $lower->exitCode);

        // One declaration each, at different lines of the same file — not one row answering
        // both questions.
        $this->assertNotSame($this->firstLine($upper->output), $this->firstLine($lower->output));
    }

    private function firstLine(string $jsonLines): int
    {
        $first = strtok($jsonLines, "\n");

        $this->assertIsString($first);

        $record = json_decode($first, true);

        $this->assertIsArray($record);
        $this->assertIsInt($record['line'] ?? null);

        return $record['line'];
    }
}

<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Source;

use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Source\SourceFile;

final class SourceFileTest extends TestCase
{
    public function test_it_maps_offsets_to_lines_and_columns(): void
    {
        $file = SourceFile::fromString("one\ntwo\nthree\n", 'x.php');

        $this->assertSame(1, $file->lineAt(0));
        $this->assertSame(1, $file->columnAt(0));
        $this->assertSame(2, $file->lineAt(4));
        $this->assertSame(1, $file->columnAt(4));
        $this->assertSame(3, $file->lineAt(10));
        $this->assertSame(3, $file->columnAt(10));
    }

    public function test_it_counts_columns_in_bytes_so_they_match_the_parser(): void
    {
        $file = SourceFile::fromString("<?php // é\n", 'x.php');

        // "é" is two bytes in UTF-8, so the byte after it is column 12, not 11. Reporting
        // characters instead would put `file:line:col` one short of where the parser said.
        $this->assertSame(12, $file->columnAt(11));
    }

    public function test_it_returns_whole_lines_including_their_indentation(): void
    {
        $file = SourceFile::fromString("a\n    b\n        c\nd\n", 'x.php');

        $this->assertSame("    b\n        c", $file->lines(2, 3));
    }

    public function test_a_line_range_past_the_end_is_clamped_rather_than_padded(): void
    {
        $file = SourceFile::fromString("a\nb\n", 'x.php');

        $this->assertSame("a\nb", $file->lines(1, 99));
        $this->assertSame('', $file->lines(50, 99));
    }

    public function test_a_slice_is_the_exact_bytes_between_two_offsets(): void
    {
        $file = SourceFile::fromString('abcdef', 'x.php');

        $this->assertSame('bcd', $file->slice(1, 3));
    }

    public function test_a_file_without_a_trailing_newline_still_has_its_last_line(): void
    {
        $file = SourceFile::fromString("a\nb", 'x.php');

        $this->assertSame(2, $file->lineCount());
        $this->assertSame('b', $file->lines(2, 2));
    }
}

<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Output;

use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Output\JsonRenderer;
use Vusys\Exocomp\Output\OutputFormat;
use Vusys\Exocomp\Output\PathsRenderer;
use Vusys\Exocomp\Output\Result;
use Vusys\Exocomp\Output\Row;
use Vusys\Exocomp\Output\Section;
use Vusys\Exocomp\Output\TextRenderer;
use Vusys\Exocomp\Symbol\Confidence;

final class RenderersTest extends TestCase
{
    public function test_text_leads_with_the_header_and_right_aligns_line_numbers(): void
    {
        $rendered = (new TextRenderer)->render(new Result(
            header: 'app/F.php  App\F',
            sections: [new Section('', [
                new Row('app/F.php', 7, 5, 'App\F::a', 'method', 'public function a(): void'),
                new Row('app/F.php', 142, 5, 'App\F::b', 'method', 'public function b(): void'),
            ])],
        ));

        $this->assertSame([
            'app/F.php  App\F',
            '    7  public function a(): void',
            '  142  public function b(): void',
        ], explode("\n", $rendered));
    }

    public function test_text_reports_elided_rows_rather_than_stopping_silently(): void
    {
        $rendered = (new TextRenderer)->render(new Result(
            sections: [new Section('', [new Row('app/F.php', 1, 1, null, null, 'kept')])],
            elided: 47,
        ));

        $this->assertStringContainsString('… 47 more (use --limit)', $rendered);
    }

    public function test_text_groups_what_it_will_not_answer_into_a_trailing_block_with_reasons(): void
    {
        $rendered = (new TextRenderer)->render(new Result(
            sections: [new Section('own', [new Row('app/F.php', 1, 1, null, null, 'public function a(): void')])],
            unresolvedTitle: 'not listed',
            unresolved: [
                new Row('app/F.php', 1, 1, 'App\Base', 'extends', 'extends App\Base', Confidence::Textual, 'members not listed here'),
                new Row('app/F.php', 1, 1, 'App\T', 'use', 'use App\T', Confidence::Textual, 'members not listed here'),
            ],
        ));

        $this->assertStringContainsString('── 2 not listed ──', $rendered);

        // The reason column is aligned, so the shorter entry is padded out to meet it.
        $this->assertStringContainsString('extends App\Base   members not listed here', $rendered);
        $this->assertStringContainsString('use App\T          members not listed here', $rendered);
    }

    public function test_json_emits_one_object_per_row_with_a_shared_field_order(): void
    {
        $rendered = (new JsonRenderer)->render(new Result(
            sections: [new Section('own', [
                new Row('app/F.php', 7, 5, 'App\F::a', 'method', 'public function a(): void', extra: ['origin' => 'own']),
            ])],
        ));

        $this->assertSame(
            '{"file":"app/F.php","line":7,"col":5,"fqn":"App\\\\F::a","kind":"method","confidence":"resolved","text":"public function a(): void","origin":"own","section":"own"}',
            $rendered,
        );
    }

    public function test_json_terminates_a_capped_result_with_an_elided_record(): void
    {
        $rendered = (new JsonRenderer)->render(new Result(
            sections: [new Section('', [new Row('app/F.php', 1, 1, null, null, 'kept')])],
            elided: 3,
        ));

        $this->assertSame('{"elided":3}', explode("\n", $rendered)[1]);
    }

    public function test_json_emits_a_single_object_for_singleton_commands(): void
    {
        $rendered = (new JsonRenderer)->render(new Result(object: ['file' => 'app/F.php', 'source' => "a\nb"]));

        $this->assertSame('{"file":"app/F.php","source":"a\nb"}', $rendered);
    }

    public function test_paths_deduplicates_and_keeps_first_seen_order(): void
    {
        $rendered = (new PathsRenderer)->render(new Result(
            sections: [new Section('', [
                new Row('b.php', 1, 1, null, null, 'x'),
                new Row('a.php', 2, 1, null, null, 'y'),
                new Row('b.php', 3, 1, null, null, 'z'),
            ])],
        ));

        $this->assertSame("b.php\na.php", $rendered);
    }

    public function test_paths_falls_back_to_the_singleton_payload(): void
    {
        $rendered = (new PathsRenderer)->render(new Result(object: ['file' => 'app/F.php']));

        $this->assertSame('app/F.php', $rendered);
    }

    public function test_an_unknown_format_is_rejected_with_the_known_ones_named(): void
    {
        $this->expectExceptionMessage('unknown --format "yaml" (known: text, json, paths)');

        OutputFormat::parse('yaml');
    }
}

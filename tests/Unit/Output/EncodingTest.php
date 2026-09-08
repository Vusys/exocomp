<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Output;

use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Analysis\Analyser;
use Vusys\Exocomp\Analysis\Declaration;
use Vusys\Exocomp\Output\JsonRenderer;
use Vusys\Exocomp\Output\Result;
use Vusys\Exocomp\Output\Row;
use Vusys\Exocomp\Output\Section;
use Vusys\Exocomp\Source\SourceFile;
use Vusys\Exocomp\Tests\Support\Cli;

/**
 * Text that is not plain ASCII, which real source is full of.
 *
 * Both cases here came out of rendering every declaration in a 3.9M-line vendor tree: eleven
 * records encoded to an empty JSON object and went into the stream indistinguishable from
 * records that genuinely had no fields. The cause was a value capped by bytes rather than by
 * characters; the reason it was silent was an encoder that fell back to `{}`.
 */
final class EncodingTest extends TestCase
{
    public function test_a_capped_value_is_cut_on_a_character_boundary(): void
    {
        foreach (['GREETING', 'persian', 'emoji'] as $name) {
            $signature = $this->member($name)->signature;

            $this->assertTrue(
                mb_check_encoding($signature, 'UTF-8'),
                "{$name}: the capped signature is not valid UTF-8, so a byte cut split a character",
            );
            $this->assertStringEndsWith('…', $signature);
        }
    }

    public function test_a_short_value_is_not_capped_at_all(): void
    {
        $run = Cli::inCorpus(['members', 'Fixtures\TierA\Baseline', '--own']);

        $this->assertStringContainsString("public const string DEFAULT_LABEL = 'baseline'", $run->output);
        $this->assertStringNotContainsString("'baseline'…", $run->output);
    }

    public function test_every_json_record_for_multibyte_source_decodes(): void
    {
        $run = Cli::inCorpus(['members', 'Fixtures\TierB\Encoding', '--json']);

        foreach ($run->lines() as $line) {
            $decoded = json_decode($line, true);

            $this->assertIsArray($decoded, "not decodable: {$line}");
            $this->assertNotSame([], $decoded, 'an empty record is indistinguishable from a dropped one');
        }
    }

    /**
     * A string literal in another encoding is legal PHP, so bytes that are not valid UTF-8 do
     * reach the renderer. The record must still arrive, and must say what happened to it.
     */
    public function test_an_unencodable_record_is_declared_rather_than_dropped(): void
    {
        $rendered = (new JsonRenderer)->render(new Result(
            sections: [new Section('', [
                new Row('app/F.php', 7, 5, 'App\F::latin', 'constant', "public const X = '".chr(0xFF)."'"),
            ])],
        ));

        $decoded = json_decode($rendered, true);

        $this->assertIsArray($decoded);
        $this->assertSame('app/F.php', $decoded['file']);
        $this->assertSame(7, $decoded['line']);
        $this->assertArrayHasKey('encoding', $decoded);
    }

    private function member(string $name): Declaration
    {
        $analysis = (new Analyser)->analyse(
            SourceFile::fromPath(Cli::corpusFile('src/TierB/Encoding.php'), 'src/TierB/Encoding.php'),
        );

        $class = $analysis->classLike('Fixtures\TierB\Encoding');
        $this->assertInstanceOf(Declaration::class, $class);

        $member = $class->member($name, isProperty: $name !== 'GREETING');
        $this->assertInstanceOf(Declaration::class, $member, "Encoding::{$name} not found");

        return $member;
    }
}

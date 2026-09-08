<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Tests\Support\Cli;

/**
 * The invariants that hold across every command, asserted end to end.
 *
 * These are the output contract from the first commit rather than a retrofit: text by default,
 * `--json` opt-in, address-first records, visible truncation, and a structure that does not
 * change based on whether output is a terminal.
 */
final class OutputContractTest extends TestCase
{
    public function test_text_is_the_default(): void
    {
        $run = Cli::inCorpus(['outline', 'src/TierA/Status.php']);

        $this->assertStringStartsWith('src/TierA/Status.php  Fixtures\TierA\Status', $run->output);
        $this->assertStringNotContainsString('{"', $run->output);
    }

    public function test_show_emits_source_verbatim_rather_than_through_the_console_formatter(): void
    {
        $run = Cli::inCorpus(['show', 'Fixtures\TierA\Types::disjunctiveNormalForm']);

        // Symfony's formatter reads `<...>` as style tags, and PHP source is full of `<`.
        // Emitting through it would eat exactly the payload this command exists to deliver.
        $this->assertStringContainsString('(Countable&Stringable)|null $value', $run->output);
        $this->assertSame(0, $run->exitCode);
    }

    public function test_show_reproduces_the_original_bytes_of_the_span(): void
    {
        $run = Cli::inCorpus(['show', 'Fixtures\TierA\Baseline::greet']);
        $source = file_get_contents(Cli::corpusFile('src/TierA/Baseline.php'));

        $this->assertIsString($source);

        $body = implode("\n", array_slice($run->lines(), 1));
        $this->assertStringContainsString($body, $source);
    }

    public function test_json_is_opt_in_and_emits_one_object_per_line(): void
    {
        $run = Cli::inCorpus(['members', 'Fixtures\TierA\Types', '--json']);

        foreach ($run->lines() as $line) {
            $decoded = json_decode($line, true);

            $this->assertIsArray($decoded, "not a JSON object: {$line}");
            $this->assertSame(['file', 'line', 'col', 'fqn', 'kind', 'confidence', 'text'], array_slice(array_keys($decoded), 0, 7));
            $this->assertSame('resolved', $decoded['confidence']);
        }
    }

    public function test_the_json_flag_and_the_format_option_agree(): void
    {
        $this->assertSame(
            Cli::inCorpus(['members', 'Fixtures\TierA\Types', '--json'])->output,
            Cli::inCorpus(['members', 'Fixtures\TierA\Types', '--format', 'json'])->output,
        );
    }

    public function test_a_limit_reports_what_it_dropped_in_both_formats(): void
    {
        $text = Cli::inCorpus(['members', 'Fixtures\TierA\Types', '--limit', '2']);
        $json = Cli::inCorpus(['members', 'Fixtures\TierA\Types', '--limit', '2', '--json']);

        $this->assertStringContainsString('… 6 more (use --limit)', $text->output);
        $this->assertStringContainsString('{"elided":6}', $json->output);
    }

    public function test_a_token_ceiling_reports_the_lines_it_cut(): void
    {
        $run = Cli::inCorpus(['show', 'Fixtures\TierA\Types', '--max-tokens', '10']);

        $this->assertStringContainsString('more lines (use --max-tokens)', $run->output);
    }

    public function test_paths_format_emits_deduplicated_file_paths(): void
    {
        $run = Cli::inCorpus(['members', 'Fixtures\TierA\Baseline', '--format', 'paths']);

        $this->assertSame(['src/TierA/Baseline.php'], $run->lines());
    }

    public function test_paths_format_works_for_show_too(): void
    {
        $run = Cli::inCorpus(['show', 'Fixtures\TierA\Baseline::greet', '--format', 'paths']);

        $this->assertSame(['src/TierA/Baseline.php'], $run->lines());
    }

    public function test_every_record_leads_with_an_address(): void
    {
        $run = Cli::inCorpus(['members', 'Fixtures\TierA\Types', '--json']);

        foreach ($run->lines() as $line) {
            $decoded = json_decode($line, true);

            $this->assertIsArray($decoded);
            $this->assertSame('src/TierA/Types.php', $decoded['file']);
            $this->assertIsInt($decoded['line']);
            $this->assertIsInt($decoded['col']);
        }
    }

    public function test_members_hands_back_what_it_will_not_answer_with_the_command_that_would(): void
    {
        $run = Cli::inCorpus(['members', 'Fixtures\TierA\Baseline']);

        $this->assertStringContainsString('── 3 not listed ──', $run->output);
        $this->assertStringContainsString('exocomp members Fixtures\TierA\AbstractBase', $run->output);
        $this->assertStringContainsString('exocomp members Fixtures\TierA\Contract', $run->output);
        $this->assertStringContainsString('exocomp members Fixtures\TierA\Loggable', $run->output);
    }

    public function test_the_own_flag_suppresses_that_block(): void
    {
        $run = Cli::inCorpus(['members', 'Fixtures\TierA\Baseline', '--own']);

        $this->assertStringNotContainsString('not listed', $run->output);
    }

    public function test_outline_and_imports_accept_a_class_name_as_well_as_a_path(): void
    {
        $byName = Cli::inCorpus(['outline', 'Fixtures\TierA\Status']);
        $byPath = Cli::inCorpus(['outline', 'src/TierA/Status.php']);

        $this->assertSame($byPath->output, $byName->output);
        $this->assertSame(0, Cli::inCorpus(['imports', 'Fixtures\TierB\Aliases'])->exitCode);
    }

    public function test_a_file_with_no_imports_says_so_rather_than_printing_nothing(): void
    {
        $run = Cli::inCorpus(['imports', 'src/TierA/Status.php']);

        $this->assertSame(0, $run->exitCode);
        $this->assertSame('src/TierA/Status.php  Fixtures\TierA  (0 imports)', trim($run->output));
    }
}

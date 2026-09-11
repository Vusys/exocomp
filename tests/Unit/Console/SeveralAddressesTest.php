<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Tests\Support\Cli;
use Vusys\Exocomp\Tests\Support\Run;

/**
 * Reading several members back, taken from real sessions.
 *
 * The observed pattern was `members` followed by `sed -n 'X,Yp'`: having learned where three
 * members are, the reader wanted their bodies, and one line range beat three round trips. A
 * range is only right while the members sit in one file, contiguously, with nothing else
 * between them — so the tool has to make naming them cheaper than guessing a span.
 */
final class SeveralAddressesTest extends TestCase
{
    public function test_several_addresses_are_printed_in_the_order_asked_for(): void
    {
        $run = $this->show('Fixtures\TierA\Baseline::repository', 'Fixtures\TierA\Baseline::greet');

        $this->assertSame(0, $run->exitCode);
        $this->assertLessThan(
            strpos($run->output, 'function greet'),
            strpos($run->output, 'function repository'),
            'the order asked for is the order printed, not the order they appear in the file',
        );
    }

    public function test_addresses_need_not_share_a_class(): void
    {
        $run = $this->show('Fixtures\TierA\Baseline::greet', 'Fixtures\TierA\Support\Repository::find');

        $this->assertSame(0, $run->exitCode);
        $this->assertStringContainsString('src/TierA/Baseline.php', $run->output);
        $this->assertStringContainsString('src/TierA/Support/Repository.php', $run->output);
    }

    public function test_one_missing_address_does_not_withdraw_the_others(): void
    {
        $run = $this->show('Fixtures\TierA\Baseline::greet', 'Fixtures\TierA\Baseline::noSuchMember');

        $this->assertStringContainsString('function greet', $run->output);
        $this->assertStringContainsString('── 1 not found ──', $run->output);
    }

    public function test_a_partial_answer_exits_not_found(): void
    {
        // `exocomp show a b && …` must not proceed on two thirds of an answer.
        $run = $this->show('Fixtures\TierA\Baseline::greet', 'Fixtures\TierA\Baseline::noSuchMember');

        $this->assertSame(1, $run->exitCode);
        $this->assertStringContainsString('1 of 2 addresses found', $run->output);
    }

    public function test_a_missing_address_still_gets_the_namespace_hint(): void
    {
        $run = $this->show('Fixtures\TierA\Baseline::greet', 'Fixtures\Wrong\Place\Repository');

        $this->assertStringContainsString('"Repository" is declared as Fixtures\TierA\Support\Repository', $run->output);
        $this->assertStringNotContainsString('pass --scan', $run->output);
    }

    public function test_one_address_keeps_the_answer_it_has_always_had(): void
    {
        $run = $this->show('Fixtures\TierA\Baseline::greet');

        $this->assertSame(0, $run->exitCode);
        // No summary line and no trailing block — a lone header, then the source.
        $this->assertStringNotContainsString('addresses', $run->output);
        $this->assertStringStartsWith('src/TierA/Baseline.php:', $run->output);
    }

    public function test_one_address_still_yields_a_single_json_object(): void
    {
        $run = $this->show('Fixtures\TierA\Baseline::greet', '--json');

        $decoded = json_decode(trim($run->output), true);

        $this->assertIsArray($decoded);
        $this->assertSame('Fixtures\TierA\Baseline::greet', $decoded['fqn'] ?? null);
    }

    public function test_several_addresses_yield_one_json_record_each(): void
    {
        $run = $this->show('Fixtures\TierA\Baseline::greet', 'Fixtures\TierA\Baseline::repository', '--json');

        $lines = $run->lines();

        $this->assertCount(2, $lines);
        $this->assertSame('Fixtures\TierA\Baseline::greet', $this->fqn($lines[0]));
        $this->assertSame('Fixtures\TierA\Baseline::repository', $this->fqn($lines[1]));
    }

    public function test_a_partial_json_answer_leads_with_the_verdict(): void
    {
        $run = $this->show('Fixtures\TierA\Baseline::greet', 'Fixtures\TierA\Baseline::noSuchMember', '--json');

        $first = json_decode($run->lines()[0], true);

        $this->assertIsArray($first);
        $this->assertFalse($first['found'] ?? null, 'a consumer reading the stream learns the answer is partial before it reads the records');
    }

    public function test_paths_format_names_every_file_reached(): void
    {
        $run = $this->show('Fixtures\TierA\Baseline::greet', 'Fixtures\TierA\Support\Repository::find', '--format=paths');

        $this->assertSame(
            ['src/TierA/Baseline.php', 'src/TierA/Support/Repository.php'],
            $run->lines(),
        );
    }

    public function test_paths_format_omits_an_address_that_has_no_file(): void
    {
        // The format is shaped for `xargs`, where a blank line is an empty argument.
        $run = $this->show('Fixtures\TierA\Baseline::greet', 'Fixtures\TierA\Baseline::noSuchMember', '--format=paths');

        $this->assertSame(['src/TierA/Baseline.php'], $run->lines());
    }

    private function fqn(string $line): ?string
    {
        $decoded = json_decode($line, true);

        $this->assertIsArray($decoded);

        $fqn = $decoded['fqn'] ?? null;

        return is_string($fqn) ? $fqn : null;
    }

    private function show(string ...$arguments): Run
    {
        Cli::inCorpus(['index', '--warm']);

        return Cli::inCorpus(array_values(['show', ...$arguments]));
    }
}

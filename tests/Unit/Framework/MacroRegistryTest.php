<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Framework;

use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Tests\Support\Cli;

/**
 * Members bolted on from somewhere else.
 *
 * The design report calls macros genuinely unresolvable, and it is right about the part that
 * matters: nothing static can say whether the provider that registers one has run on the path
 * being asked about. What it can say is where the registration is, and these assert that the
 * answer stops exactly there — a location, never a target.
 */
final class MacroRegistryTest extends TestCase
{
    public function test_a_macro_is_answered_with_where_it_was_registered(): void
    {
        $call = $this->call('App\Services\OrderQueries::summary');

        $this->assertSame('inferred', $call['confidence']);
        $this->assertStringContainsString('a macro of this name is registered', $call['reason']);
        $this->assertStringContainsString('app/Providers/MacroServiceProvider.php:19', $call['reason']);
    }

    public function test_the_answer_does_not_claim_the_call_goes_there(): void
    {
        // A closure is not a declaration. Naming one as the target would invent a declaration
        // out of an argument, and give the walk something to step into that does not exist.
        $this->assertStringContainsString(
            'whether it is registered on this path is not something the source says',
            $this->call('App\Services\OrderQueries::summary')['reason'],
        );
    }

    public function test_a_computed_registration_name_registers_nothing(): void
    {
        $run = Cli::inLaravel(['calls-from', 'App\Providers\MacroServiceProvider::boot', '--json']);

        $this->assertStringNotContainsString('"fqn":"App\\\\Services\\\\ReportBuilder::computed"', $run->output);
    }

    /**
     * @return array{fqn: ?string, confidence: string, reason: string}
     */
    private function call(string $symbol): array
    {
        $run = Cli::inLaravel(['calls-from', $symbol, '--json']);

        foreach ($run->lines() as $line) {
            $decoded = json_decode($line, true);

            if (! is_array($decoded) || ! isset($decoded['kind'])) {
                continue;
            }

            return [
                'fqn' => is_string($decoded['fqn'] ?? null) ? $decoded['fqn'] : null,
                'confidence' => is_string($decoded['confidence'] ?? null) ? $decoded['confidence'] : '',
                'reason' => is_string($decoded['reason'] ?? null) ? $decoded['reason'] : '',
            ];
        }

        self::fail("no call records from {$symbol}");
    }
}

<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Inference;

use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Tests\Support\Cli;

/**
 * Members that exist at runtime and are not declared anywhere a parser can see.
 *
 * The specification here is a contrast: `Bag` and `AnnotatedBag` have identical bodies, and the
 * only difference between them is that one writes down what its magic does. If the two ever
 * produce the same answer the confidence model has collapsed — either the tool has started
 * guessing, or it has stopped reading what it was told — and the write side is unsafe in
 * whichever direction it went.
 */
final class MagicMembersTest extends TestCase
{
    public function test_the_reason_says_a_class_answers_through_call(): void
    {
        $run = Cli::inCorpus(['reaches', 'Fixtures\TierD\MagicCallers::throughBag', 'Fixtures\TierD\Notifier::send']);

        // "declares no fetch()" reads as a broken call. It is not one: it is a call whose far
        // end cannot be seen, and a reader should do something different about each.
        $this->assertStringContainsString('declares __call()', $run->output);
    }

    public function test_a_class_with_no_parent_is_never_told_it_has_one(): void
    {
        $run = Cli::inCorpus(['graph', 'Fixtures\TierD\MagicCallers::throughBag', '--depth', '1']);

        $this->assertStringNotContainsString('a parent or trait outside the readable source does', $run->output);
    }

    public function test_calling_a_typed_value_resolves_to_its_invoke(): void
    {
        $call = $this->call('Fixtures\TierD\MagicCallers::throughInvoke');

        // The call site writes no method name at all, which is exactly why a textual search
        // cannot find it and why the tool has to supply it.
        $this->assertSame('Fixtures\TierD\Invokable::__invoke', $call['fqn']);
        $this->assertSame('resolved', $call['confidence']);
        $this->assertSame('invoke', $call['kind']);
    }

    public function test_calling_an_untyped_value_stays_unknowable(): void
    {
        $call = $this->call('Fixtures\TierD\MagicCallers::throughUntypedInvoke');

        $this->assertNull($call['fqn']);
        $this->assertSame('dynamic function name', $call['reason']);
    }

    /**
     * The first call record a declaration makes, decoded.
     *
     * @return array{fqn: ?string, confidence: string, reason: string, kind: string}
     */
    private function call(string $symbol): array
    {
        $run = Cli::inCorpus(['calls-from', $symbol, '--json']);

        $this->assertSame(0, $run->exitCode, $run->errors);

        foreach ($run->lines() as $line) {
            $decoded = json_decode($line, true);

            if (! is_array($decoded) || ! isset($decoded['kind'])) {
                continue;
            }

            return [
                'fqn' => is_string($decoded['fqn'] ?? null) ? $decoded['fqn'] : null,
                'confidence' => is_string($decoded['confidence'] ?? null) ? $decoded['confidence'] : '',
                'reason' => is_string($decoded['reason'] ?? null) ? $decoded['reason'] : '',
                'kind' => is_string($decoded['kind']) ? $decoded['kind'] : '',
            ];
        }

        self::fail("no call records from {$symbol}");
    }
}

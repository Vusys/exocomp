<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Inference;

use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Inference\OutgoingCall;
use Vusys\Exocomp\Symbol\Confidence;
use Vusys\Exocomp\Tests\Support\Cli;

/**
 * What the inference engine reads, and — more importantly — where it stops.
 *
 * A general PHP type engine is a very large thing, and most of that bulk exists to recover
 * types from code that never wrote them down. This one reads what is written, plus the narrow
 * reading of a class's own assignments and returns that BodyTypes does, and every
 * test that asserts an *unknown* result is as much the specification as the ones that assert a
 * type: a receiver the tool cannot know and claims to know is worse than one it admits to.
 */
final class TypeResolverTest extends TestCase
{
    public function test_a_declared_parameter_type_resolves(): void
    {
        $this->assertResolvedIn('Fixtures\TierD\NotifierCallers::viaParameter');
    }

    public function test_a_promoted_property_type_resolves(): void
    {
        $this->assertResolvedIn('Fixtures\TierD\NotifierCallers::direct');
    }

    public function test_an_instantiation_resolves_the_variable_it_is_assigned_to(): void
    {
        $this->assertResolvedIn('Fixtures\TierD\NotifierCallers::fromNew');
    }

    public function test_an_untyped_property_and_return_are_inferred_from_the_class_code(): void
    {
        foreach (['viaAssignedProperty' => 'every assignment in the class', 'viaVarTag' => '@var', 'viaUntypedReturn' => 'every return in its body'] as $method => $reason) {
            $call = array_values(array_filter(
                $this->callsFrom('Fixtures\TierD\LegacyCallers', $method),
                static fn (OutgoingCall $c): bool => $c->target === 'Fixtures\TierD\Notifier::send',
            ));

            $this->assertCount(1, $call, $method);
            $this->assertSame(Confidence::Inferred, $call[0]->confidence, $method);
            $this->assertStringContainsString($reason, $call[0]->reason, $method);
        }
    }

    public function test_an_element_of_a_documented_array_is_inferred(): void
    {
        foreach (['viaShapeKey' => '@return', 'viaForeach' => '@var', 'viaParam' => '@param', 'viaInlineVar' => '@var'] as $method => $tag) {
            $call = array_values(array_filter(
                $this->callsFrom('Fixtures\TierD\ArrayCallers', $method),
                static fn (OutgoingCall $c): bool => $c->target === 'Fixtures\TierD\Notifier::send',
            ));

            $this->assertCount(1, $call, $method);
            $this->assertSame(Confidence::Inferred, $call[0]->confidence, $method);
            $this->assertStringContainsString("an element of an array documented by {$tag}", $call[0]->reason, $method);
        }

        $missing = array_map(static fn (OutgoingCall $c): ?string => $c->target, $this->callsFrom('Fixtures\TierD\ArrayCallers', 'viaMissingKey'));
        $this->assertNotContains('Fixtures\TierD\Notifier::send', $missing);
    }

    public function test_a_method_return_type_resolves_a_chained_call(): void
    {
        $calls = $this->callsFrom('Fixtures\TierB\CallSites', 'lookups');
        $targets = array_map(static fn (OutgoingCall $c): ?string => $c->target, $calls);

        // `$this->repository->find(...)` resolves through the promoted property's declared type.
        $this->assertContains('Fixtures\TierA\Support\Repository::find', $targets);
    }

    public function test_a_global_function_is_not_reported_as_a_namespaced_one(): void
    {
        $targets = array_map(
            static fn (OutgoingCall $c): ?string => $c->target,
            $this->callsFrom('Fixtures\TierA\Baseline', 'greet'),
        );

        // An unqualified call falls back to the global namespace at runtime when the namespaced
        // function does not exist, so reporting `Fixtures\TierA\str_repeat` would be wrong.
        $this->assertContains('str_repeat', $targets);
        $this->assertNotContains('Fixtures\TierA\str_repeat', $targets);
    }

    public function test_a_variable_method_name_is_admitted_rather_than_guessed(): void
    {
        $calls = $this->callsFrom('Fixtures\TierD\NotifierCallers', 'dynamic');
        $unresolved = array_values(array_filter($calls, static fn (OutgoingCall $c): bool => $c->target === null));

        $this->assertNotSame([], $unresolved);
        $this->assertSame(Confidence::Textual, $unresolved[0]->confidence);
        $this->assertStringContainsString('variable method name', $unresolved[0]->reason);
    }

    public function test_an_untyped_receiver_is_admitted_rather_than_guessed(): void
    {
        $calls = $this->callsFrom('Fixtures\TierD\NotifierCallers', 'untyped');
        $unresolved = array_values(array_filter($calls, static fn (OutgoingCall $c): bool => $c->target === null));

        $this->assertNotSame([], $unresolved);
        $this->assertStringContainsString('receiver type unknown', $unresolved[0]->reason);
    }

    /**
     * @return list<OutgoingCall>
     */
    private function callsFrom(string $class, string $method): array
    {
        $run = Cli::inCorpus(['calls-from', $class.'::'.$method, '--json']);

        $this->assertSame(0, $run->exitCode, $run->errors);

        $calls = [];

        foreach ($run->lines() as $line) {
            $decoded = json_decode($line, true);

            if (! is_array($decoded) || ! isset($decoded['kind'])) {
                continue;
            }

            // Narrowed rather than cast: the records come back as `mixed` from `json_decode`,
            // and asserting their shape here is part of what the test is checking.
            $calls[] = new OutgoingCall(
                path: $this->text($decoded, 'file'),
                line: $this->number($decoded, 'line'),
                column: $this->number($decoded, 'col'),
                kind: $this->text($decoded, 'kind'),
                target: is_string($decoded['fqn'] ?? null) ? $decoded['fqn'] : null,
                confidence: Confidence::from($this->text($decoded, 'confidence')),
                reason: $this->text($decoded, 'reason'),
                snippet: $this->text($decoded, 'source'),
            );
        }

        return $calls;
    }

    /**
     * Keyed on the declaration the reference sits in rather than on a line number, so that
     * adding a case to a fixture cannot silently break an unrelated assertion.
     */
    /**
     * @param  array<array-key, mixed>  $record
     */
    private function text(array $record, string $key): string
    {
        $value = $record[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * @param  array<array-key, mixed>  $record
     */
    private function number(array $record, string $key): int
    {
        $value = $record[$key] ?? null;

        return is_int($value) ? $value : 0;
    }

    private function assertResolvedIn(string $context): void
    {
        $run = Cli::inCorpus(['references-to', 'Fixtures\TierD\Notifier::send', '--json']);
        $found = false;

        foreach ($run->lines() as $record) {
            $decoded = json_decode($record, true);

            if (is_array($decoded) && ($decoded['context'] ?? null) === $context) {
                $found = true;
                $this->assertSame('resolved', $decoded['confidence'], $context);
            }
        }

        $this->assertTrue($found, "no reference inside {$context}");
    }
}

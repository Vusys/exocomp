<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Inference;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Tests\Support\Cli;

/**
 * Shapes whose type PHP fixes but the resolver used to drop: a first-class callable, a caught
 * exception, `self` in a trait, `match`, `??=`, enum built-ins, closure parameters, `|>` and
 * clone-with. Each probe in App\Inference\InferenceProbes calls Gateway::refund once it is typed.
 */
final class InferenceShapesTest extends TestCase
{
    private const string GATEWAY_REFUND = 'App\Inference\Gateway::refund';

    /**
     * @return iterable<string, array{string}>
     */
    public static function typedProbes(): iterable
    {
        yield 'calling a first-class callable' => ['callableInvoked'];
        yield 'a caught exception' => ['caught'];
        yield 'self in a trait, called statically' => ['traitSelfStatic'];
        yield 'self in a trait, called on an instance' => ['traitSelfInstance'];
        yield 'a match whose arms agree' => ['matched'];
        yield 'an arm of match (true)' => ['matchTrue'];
        yield 'a ??= assignment' => ['coalesceAssigned'];
        yield 'a backed enum from()' => ['enumFrom'];
        yield 'a typed closure parameter' => ['closureParameter'];
        yield 'a pipe into a first-class callable' => ['piped'];
        yield 'a pipe into an arrow function' => ['pipedArrow'];
        yield 'clone with' => ['clonedWith'];
    }

    #[DataProvider('typedProbes')]
    public function test_the_shape_types_its_receiver(string $method): void
    {
        $calls = $this->callsFrom('App\Inference\InferenceProbes::'.$method);

        $this->assertContains(self::GATEWAY_REFUND, array_column($calls, 'fqn'), $method);
    }

    public function test_a_first_class_callable_is_a_closure_not_what_its_method_returns(): void
    {
        $calls = $this->callsFrom('App\Inference\InferenceProbes::callableAsObject');

        $this->assertNotContains(self::GATEWAY_REFUND, array_column($calls, 'fqn'));
        $reasons = array_map(static fn (array $call): string => is_string($call['reason'] ?? null) ? $call['reason'] : '', $calls);

        $this->assertStringContainsString('a Closure has no refund()', implode("\n", $reasons));
    }

    public function test_calling_a_first_class_callable_is_a_call_to_the_method_it_holds(): void
    {
        $calls = $this->callsFrom('App\Inference\InferenceProbes::callableInvoked');

        $invoked = array_values(array_filter($calls, static fn (array $call): bool => $call['kind'] === 'invoke'));

        $this->assertSame('App\Inference\Holder::make', $invoked[0]['fqn'] ?? null);
    }

    public function test_an_undeclared_property_is_typed_from_its_assignments(): void
    {
        $calls = $this->callsFrom('App\Inference\LegacyService::refund');

        $this->assertContains(self::GATEWAY_REFUND, array_column($calls, 'fqn'));
    }

    public function test_clone_with_is_not_listed_as_a_function_call(): void
    {
        $calls = $this->callsFrom('App\Inference\InferenceProbes::clonedWith');

        $this->assertNotContains('clone', array_column($calls, 'fqn'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function callsFrom(string $address): array
    {
        $run = Cli::inLaravel(['calls-from', $address, '--json', '--max-tokens', '0']);

        $this->assertSame(0, $run->exitCode, $run->errors);

        $calls = [];

        foreach (explode("\n", trim($run->output)) as $line) {
            $record = json_decode($line, true);

            if (is_array($record) && array_key_exists('kind', $record)) {
                $calls[] = $record;
            }
        }

        return $calls;
    }
}

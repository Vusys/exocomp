<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Inference;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Tests\Support\Cli;

/**
 * Eloquent chains that stopped, or named a member that does not exist: an untyped relation, a
 * relation's own methods, `#[Scope]` and legacy scopes on a builder, a forwarded static call,
 * `when()`, callbacks, `tap()`, an untyped factory state, and a collection read from `vendor/`.
 */
final class EloquentShapesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function chains(): iterable
    {
        yield 'an untyped relation method' => ['untypedRelation', 'App\Models\Invoice::send', 'inferred'];
        yield 'a method the relation declares' => ['relationSave', 'Illuminate\Database\Eloquent\Relations\HasOneOrMany::save', 'inferred'];
        yield 'a method the belongs-to declares' => ['relationAssociate', 'Illuminate\Database\Eloquent\Relations\BelongsTo::associate', 'inferred'];
        yield 'a method the relation forwards' => ['untypedRelation', 'Illuminate\Database\Eloquent\Builder::where', 'inferred'];
        yield 'a #[Scope] on a builder' => ['attributeScope', 'App\Inference\Shipment::paid', 'inferred'];
        yield 'past a #[Scope] on a builder' => ['attributeScope', 'App\Inference\Shipment::order', 'inferred'];
        yield 'past a #[Scope] called statically' => ['attributeScopeStatic', 'App\Inference\Shipment::order', 'inferred'];
        yield 'a legacy scope on a builder' => ['legacyScope', 'App\Inference\Shipment::scopeOpen', 'inferred'];
        yield 'a forwarded static call' => ['forwarded', 'App\Inference\Shipment::find', 'inferred'];
        yield 'past when()' => ['conditional', 'App\Inference\Shipment::order', 'inferred'];
        yield 'inside when()\'s callback' => ['conditional', 'Illuminate\Database\Eloquent\Builder::where', 'inferred'];
        yield 'inside each()\'s callback' => ['eachCallback', 'App\Inference\Shipment::order', 'inferred'];
        yield 'inside tap()\'s callback' => ['tapped', 'App\Inference\Gateway::refund', 'resolved'];
        yield 'past an untyped factory state' => ['untypedState', 'App\Models\Invoice::send', 'inferred'];
    }

    #[DataProvider('chains')]
    public function test_the_chain_reaches_the_member_that_runs(string $method, string $fqn, string $confidence): void
    {
        $calls = $this->callsFrom('App\Inference\EloquentProbes::'.$method);
        $found = array_values(array_filter($calls, static fn (array $call): bool => ($call['fqn'] ?? null) === $fqn));

        $this->assertNotSame([], $found, sprintf('%s: no call to %s in %s', $method, $fqn, implode(', ', array_map(static fn (array $call): string => is_string($call['fqn'] ?? null) ? $call['fqn'] : '?', $calls))));
        $this->assertSame($confidence, $found[0]['confidence']);
    }

    public function test_no_call_is_named_on_the_builder_that_the_builder_does_not_have(): void
    {
        foreach (['attributeScope', 'legacyScope', 'relationSave', 'relationAssociate'] as $method) {
            foreach ($this->callsFrom('App\Inference\EloquentProbes::'.$method) as $call) {
                $this->assertNotContains($call['fqn'] ?? null, [
                    'Illuminate\Database\Eloquent\Builder::paid',
                    'Illuminate\Database\Eloquent\Builder::open',
                    'Illuminate\Database\Eloquent\Builder::save',
                    'Illuminate\Database\Eloquent\Builder::associate',
                ], $method);
            }
        }
    }

    public function test_a_known_collection_is_not_a_caller_of_an_application_method_of_the_same_name(): void
    {
        $run = Cli::inLaravel(['callers-of', 'App\Inference\Picker::filter', '--all-unresolved']);

        $this->assertStringContainsString('1 caller of App\Inference\Picker::filter', $run->output);
        $this->assertStringContainsString('App\Inference\Picker::real', $run->output);
        $this->assertStringNotContainsString('declares no', $run->output);
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

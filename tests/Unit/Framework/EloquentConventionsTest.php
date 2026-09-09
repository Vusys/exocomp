<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Framework;

use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Tests\Support\Cli;

/**
 * The members Eloquent lets you spell differently from the way they are declared.
 *
 * None of these call sites writes the name of the declaration it reaches, which is the whole
 * difficulty: a rename driven by a search for `scopeActive` finds the declaration and nothing
 * else, and reports full confidence in that answer. In PHP the result breaks at runtime, on
 * whichever path happens to execute.
 *
 * They resolve rather than merely being inferred because the transform is a total function of
 * the name and the target is found and checked — but only once the installed framework has been
 * confirmed to still implement the convention. {@see EloquentConventions} degrades to inferred
 * when it cannot read the framework, and the fixture's stub carries the two members that
 * confirmation looks for, on the model and on a trait, exactly where the real one keeps them.
 */
final class EloquentConventionsTest extends TestCase
{
    public function test_a_local_scope_is_reached_by_the_name_callers_write(): void
    {
        $records = $this->references('App\Models\Order::scopeActive');

        $this->assertSame(['Order::active()', '$order->active()'], $this->calls($records));
    }

    public function test_a_scope_call_site_is_resolved_not_guessed(): void
    {
        foreach ($this->references('App\Models\Order::scopeActive') as $record) {
            $this->assertSame('resolved', $record['confidence'], $record['text']);
        }
    }

    public function test_an_accessor_is_reached_through_the_attribute_it_declares(): void
    {
        $this->assertSame(['$order->display_name'], $this->calls($this->references('App\Models\Order::getDisplayNameAttribute')));
    }

    public function test_the_newer_attribute_spelling_is_reached_too(): void
    {
        // Named in camel case and marked by its return type. The name alone proves nothing —
        // every ordinary method on a model would match the convention without the type.
        $this->assertSame(['$order->short_code'], $this->calls($this->references('App\Models\Order::shortCode')));
    }

    public function test_a_relation_read_as_an_attribute_is_inferred_not_resolved(): void
    {
        $records = array_values(array_filter(
            $this->references('App\Models\Order::customer'),
            static fn (array $record): bool => ! str_contains($record['text'], 'customer()'),
        ));

        $this->assertNotSame([], $records);

        foreach ($records as $record) {
            $this->assertSame('inferred', $record['confidence'], $record['text']);
            $this->assertStringContainsString('relation read as an attribute', $record['reason']);
        }
    }

    public function test_a_call_through_a_scope_is_a_graph_edge(): void
    {
        $run = Cli::inLaravel(['calls-from', 'App\Services\OrderQueries::active']);

        $this->assertStringContainsString('App\Models\Order::scopeActive', $run->output);
    }

    public function test_a_column_is_not_dressed_up_as_an_accessor(): void
    {
        // `placed_at` is a cast in the model and a column in the migrations. It is neither a
        // declaration nor a convention, so the read is reported on the strength of the receiver
        // alone and nothing claims to know what it reaches.
        //
        // Note what is *not* asserted here: that the tool says the member does not exist. It
        // cannot say that of a model, whose ancestry leaves the corpus at the framework's base
        // class — an absence is only provable on a class read to the end.
        foreach ($this->references('App\Models\Order::placed_at') as $record) {
            $this->assertStringNotContainsString('accessor', $record['reason']);
            $this->assertStringNotContainsString('attribute, declared as', $record['reason']);
        }
    }

    /**
     * Every reference except the declaration itself, as decoded records.
     *
     * @return list<array{text: string, confidence: string, reason: string}>
     */
    private function references(string $symbol): array
    {
        $run = Cli::inLaravel(['references-to', $symbol, '--json']);

        $records = [];

        foreach ($run->lines() as $line) {
            $decoded = json_decode($line, true);

            if (! is_array($decoded) || ! isset($decoded['text']) || isset($decoded['note']) && ! isset($decoded['confidence'])) {
                continue;
            }

            $text = is_string($decoded['text']) ? $decoded['text'] : '';

            if (str_contains($text, 'public function') || str_contains($text, 'protected function')) {
                continue;
            }

            $records[] = [
                'text' => $text,
                'confidence' => is_string($decoded['confidence'] ?? null) ? $decoded['confidence'] : '',
                'reason' => is_string($decoded['reason'] ?? null) ? $decoded['reason'] : '',
            ];
        }

        return $records;
    }

    /**
     * @param  list<array{text: string, confidence: string, reason: string}>  $records
     * @return list<string>
     */
    private function calls(array $records): array
    {
        return array_map(
            static fn (array $record): string => trim(substr($record['text'], (int) strpos($record['text'], '  '))),
            $records,
        );
    }
}

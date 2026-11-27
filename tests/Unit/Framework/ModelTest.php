<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Framework;

use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Tests\Support\Cli;

/**
 * Tier E — the framework profile's own tier, Eloquent half.
 *
 * The interesting cases are the ones a language-only tool gets wrong or cannot see: a relation
 * whose far end is a class rather than a return type, a scope registered by a trait the model
 * never mentions, a table name that exists only as a convention, and a `morphTo` that must be
 * declined rather than guessed.
 */
final class ModelTest extends TestCase
{
    public function test_a_declared_table_is_reported_as_declared(): void
    {
        $run = Cli::inLaravel(['model', 'Order']);

        $this->assertSame(0, $run->exitCode);
        $this->assertStringContainsString('shop_orders', $run->output);
        $this->assertStringNotContainsString('derived from the class name', $run->output);
    }

    public function test_an_undeclared_table_is_derived_and_says_so(): void
    {
        $output = Cli::inLaravel(['model', 'OrderLine'])->output;

        $this->assertStringContainsString('order_lines', $output);
        $this->assertStringContainsString('derived from the class name', $output);
    }

    public function test_a_derived_table_is_checked_against_the_migrations(): void
    {
        $this->assertStringContainsString(
            'the migrations do create this table',
            Cli::inLaravel(['model', 'Person'])->output,
        );
    }

    public function test_an_irregular_plural_follows_the_frameworks_inflector(): void
    {
        // `Person` becomes `people`, not `persons`. This is the case a hand-rolled pluraliser
        // gets wrong, and a wrong table name is worse than none.
        $this->assertStringContainsString('people', Cli::inLaravel(['model', 'Person'])->output);
    }

    public function test_a_relation_reports_the_class_at_the_far_end(): void
    {
        $output = Cli::inLaravel(['model', 'Order'])->output;

        $this->assertStringContainsString('belongsTo  App\Models\Customer', $output);
        $this->assertStringContainsString('hasMany  App\Models\OrderLine', $output);
    }

    public function test_a_relation_is_found_through_a_chained_call(): void
    {
        // `$this->hasMany(OrderLine::class)->orderBy(…)`. Reading only the outermost call sees
        // `orderBy` and concludes the method is not a relation.
        $this->assertStringContainsString('lines', Cli::inLaravel(['model', 'Order'])->output);
    }

    public function test_a_method_that_merely_shares_a_relations_shape_is_not_one(): void
    {
        $run = Cli::inLaravel(['model', 'Order', '--only', 'relation', '--json']);

        foreach (explode("\n", trim($run->output)) as $line) {
            $record = json_decode($line, true);

            $this->assertIsArray($record);
            $this->assertNotSame('reference', $record['name'] ?? null, 'reference() returns a string, not a relation');
        }
    }

    public function test_a_polymorphic_relation_is_declined_rather_than_guessed(): void
    {
        $output = Cli::inLaravel(['model', 'Comment'])->output;

        $this->assertStringContainsString('not read from the source', $output);
        $this->assertStringContainsString('morph map', $output);
    }

    public function test_a_scope_registered_by_a_trait_is_found(): void
    {
        // The model's own body never mentions TenantScope. This is the construct at the centre
        // of the cross-tenant bug family, and missing it is how an audit comes back clean.
        $output = Cli::inLaravel(['model', 'Order'])->output;

        $this->assertStringContainsString('App\Scopes\TenantScope', $output);
        $this->assertStringContainsString('HasTenant', $output);
    }

    public function test_an_empty_guarded_is_reported_rather_than_omitted(): void
    {
        $this->assertStringContainsString(
            'every attribute is mass-assignable',
            Cli::inLaravel(['model', 'Customer'])->output,
        );
    }

    public function test_casts_carry_the_class_they_cast_to(): void
    {
        $this->assertStringContainsString('App\Models\OrderStatus', Cli::inLaravel(['model', 'Order'])->output);
    }

    public function test_a_class_that_is_not_a_model_is_refused_with_the_command_that_would_work(): void
    {
        $run = Cli::inLaravel(['model', 'TenantScope']);

        $this->assertSame(1, $run->exitCode);
        $this->assertStringContainsString('is not an Eloquent model', $run->errors);
        $this->assertStringContainsString('exocomp members', $run->errors);
    }

    public function test_a_model_extending_a_framework_class_is_still_a_model(): void
    {
        // Account extends a class that lives outside the indexed source roots, which is the
        // ordinary shape for anything authenticatable. Walking only the index's edge table stops
        // one hop short of Model and answers "not an Eloquent model" — for a great many models.
        $run = Cli::inLaravel(['model', 'Account']);

        $this->assertSame(0, $run->exitCode);
        $this->assertStringContainsString('accounts', $run->output);
    }

    public function test_a_named_closure_scope_is_reported_by_its_name(): void
    {
        $output = Cli::inLaravel(['model', 'Account', '--only', 'scope'])->output;

        $this->assertStringContainsString('App\\Scopes\\TenantScope', $output);
        $this->assertStringContainsString('ordered', $output);
        $this->assertStringContainsString('applied by a closure', $output);
    }

    public function test_subclasses_of_a_class_outside_the_index_are_still_found(): void
    {
        // "Everything that extends Model" is one of the most useful questions there is, and its
        // subject lives outside the indexed source roots. The edges were never the problem —
        // they are recorded by the files declaring the children — but the subject was refused.
        $run = Cli::inLaravel(['subclasses', 'Illuminate\\Database\\Eloquent\\Model']);

        $this->assertSame(0, $run->exitCode);
        $this->assertStringContainsString('App\\Models\\Order', $run->output);
        $this->assertStringContainsString('outside the indexed source roots', $run->output);
    }

    public function test_a_name_nothing_can_place_is_still_refused(): void
    {
        $run = Cli::inLaravel(['subclasses', 'Nothing\\Like\\This']);

        $this->assertSame(1, $run->exitCode);
        $this->assertStringContainsString('Composer cannot place it either', $run->errors);
    }

    public function test_a_relation_read_as_an_attribute_is_a_reference_but_not_a_resolved_one(): void
    {
        // `$order->customer` is Eloquent's `__get` magic reaching the relation method. Missing
        // it would make a rename of `customer()` break at runtime; calling it resolved would
        // claim the engine can see a magic method it cannot. It is a reference, inferred, with
        // the reason attached.
        $run = Cli::inLaravel(['references-to', 'App\\Models\\Order::customer', '--json']);
        $byKind = [];

        foreach (explode("\n", trim($run->output)) as $line) {
            $record = json_decode($line, true);

            if (is_array($record) && is_string($record['kind'] ?? null)) {
                $byKind[$record['kind']] = $record;
            }
        }

        $this->assertSame('resolved', $byKind['method call']['confidence'] ?? null);
        $this->assertSame('inferred', $byKind['property fetch']['confidence'] ?? null);
        $note = $byKind['property fetch']['note'] ?? null;

        $this->assertIsString($note);

        // It used to say "this resolves through __get, if the class defines one", which was the
        // right hedge when the tool could not see the mechanism. It can now: the relation is
        // declared, and naming it is a better answer than hedging about it.
        $this->assertStringContainsString('relation read as an attribute', $note);
    }

    public function test_an_accessor_is_listed_as_the_attribute_it_exposes(): void
    {
        $run = Cli::inLaravel(['model', 'Order', '--only=accessor']);

        // 304 of these in one measured application and not one appeared here before, which left
        // `model` and `members` holding different views of the same class.
        $this->assertStringContainsString('display_name', $run->output);
        $this->assertStringContainsString('getDisplayNameAttribute()', $run->output);
    }

    public function test_both_accessor_spellings_are_listed(): void
    {
        $run = Cli::inLaravel(['model', 'Order', '--only=accessor']);

        // The newer one is recognised by its return type. Without that, every method on the
        // model would look like an attribute.
        $this->assertStringContainsString('short_code', $run->output);
        $this->assertStringContainsString('shortCode()', $run->output);
    }

    public function test_the_two_accessor_spellings_are_reported_as_the_different_mechanisms_they_are(): void
    {
        $reasons = [];

        foreach ($this->records(['model', 'Order', '--only=accessor', '--json']) as $record) {
            $name = $record['name'] ?? null;
            $note = $record['note'] ?? null;

            if (is_string($name) && is_string($note)) {
                $reasons[$name] = $note;
            }
        }

        // One is found by its name and the other by its return type, and `short_code` follows no
        // naming convention whatsoever. Describing both the same way teaches a reader the wrong
        // rule about the framework — that a method named `shortCode` cannot be an accessor, or
        // that renaming it to `getShortCodeAttribute()` would be a no-op.
        $this->assertArrayHasKey('display_name', $reasons);
        $this->assertArrayHasKey('short_code', $reasons);
        $this->assertNotSame($reasons['display_name'], $reasons['short_code']);
        $this->assertStringContainsString('naming convention', $reasons['display_name']);
        $this->assertStringContainsString('Attribute return type', $reasons['short_code']);
    }

    /**
     * @param  list<string>  $arguments
     * @return list<array<array-key, mixed>>
     */
    private function records(array $arguments): array
    {
        $found = [];

        foreach (Cli::inLaravel($arguments)->lines() as $line) {
            $decoded = json_decode($line, true);

            if (is_array($decoded)) {
                $found[] = $decoded;
            }
        }

        return $found;
    }

    public function test_annotated_properties_are_kept_apart_from_what_the_body_says(): void
    {
        $run = Cli::inLaravel(['model', 'Order', '--only=annotated', '--json']);

        foreach ($run->lines() as $line) {
            $decoded = json_decode($line, true);

            if (is_array($decoded) && isset($decoded['confidence'])) {
                $this->assertSame('inferred', $decoded['confidence']);
            }
        }
    }

    public function test_an_annotation_nothing_else_accounts_for_is_reported(): void
    {
        $run = Cli::inLaravel(['model', 'Order']);

        // The ordinary way a generated block goes wrong: a column is dropped and the comment
        // outlives it. Two of the four things needed to notice were already in hand here — the
        // migration history and the annotations — and nothing else in the toolchain will say so.
        $this->assertMatchesRegularExpression('/annotated propert(y is|ies are) backed by no column/', $run->output);
        $this->assertStringContainsString('legacy_code', $run->output);
    }

    public function test_an_annotation_the_source_accounts_for_is_not_reported_as_drift(): void
    {
        $run = Cli::inLaravel(['model', 'Order']);
        $note = explode("\n", (string) strstr($run->output, 'backed by no column'))[0];

        foreach (['id', 'reference', 'display_name'] as $accounted) {
            $this->assertStringNotContainsString($accounted, $note, "{$accounted} is a column, cast or accessor");
        }
    }

    public function test_observers_are_listed_however_they_are_registered(): void
    {
        $this->assertStringContainsString('App\Observers\CustomerObserver   registered by #[ObservedBy]', Cli::inLaravel(['model', 'Customer', '--only', 'observer'])->output);
        $this->assertStringContainsString('app/Providers/AppServiceProvider.php:47  OrderObserver', Cli::inLaravel(['model', 'Order', '--only', 'observer'])->output);
        $this->assertStringContainsString('App\Observers\InvoiceObserver', Cli::inLaravel(['model', 'Invoice', '--only', 'observer'])->output);
    }

    public function test_an_unknown_name_is_not_found(): void
    {
        $this->assertSame(1, Cli::inLaravel(['model', 'NoSuchModel'])->exitCode);
    }

    public function test_only_narrows_to_one_group(): void
    {
        $output = Cli::inLaravel(['model', 'Order', '--only', 'casts'])->output;

        $this->assertStringContainsString('placed_at', $output);
        $this->assertStringNotContainsString('belongsTo', $output);
    }
}

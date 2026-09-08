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

<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Framework;

use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Framework\Laravel\EloquentModel;
use Vusys\Exocomp\Framework\Laravel\ModelFact;
use Vusys\Exocomp\Framework\Laravel\ModelReader;
use Vusys\Exocomp\Framework\Laravel\Table;
use Vusys\Exocomp\Index\Index;
use Vusys\Exocomp\Project\AutoloadMap;
use Vusys\Exocomp\Project\ClassSource;
use Vusys\Exocomp\Project\Project;
use Vusys\Exocomp\Project\SymbolLocator;
use Vusys\Exocomp\Symbol\Confidence;
use Vusys\Exocomp\Tests\Support\Cli;

/**
 * The model reader, asserted directly.
 *
 * What matters here is not that a fact appears but that it appears with the right provenance:
 * which class declared it, and how well it is known. A relation named by `::class` and one
 * named by a string are the same string in the output and different claims about the code.
 */
final class ModelReaderTest extends TestCase
{
    public function test_a_declared_table_is_resolved_and_names_no_convention(): void
    {
        $table = $this->fact('Order', 'attribute', 'table');

        $this->assertSame('shop_orders', $table->value);
        $this->assertSame(Confidence::Resolved, $table->confidence);
        $this->assertNull($table->note);
    }

    public function test_a_derived_table_is_inferred_and_says_it_was_derived(): void
    {
        $table = $this->fact('OrderLine', 'attribute', 'table');

        $this->assertSame('order_lines', $table->value);
        $this->assertSame(Confidence::Inferred, $table->confidence);
        $this->assertStringContainsString('derived from the class name', (string) $table->note);
    }

    public function test_a_derived_table_says_whether_the_migrations_create_it(): void
    {
        $known = $this->read('OrderLine', ['order_lines' => true]);
        $unknown = $this->read('OrderLine', ['something_else' => true]);

        $this->assertStringContainsString('do create this table', (string) $this->pick($known, 'attribute', 'table')->note);
        $this->assertStringContainsString('no migration creates', (string) $this->pick($unknown, 'attribute', 'table')->note);
    }

    public function test_with_no_schema_to_check_against_it_says_that_too(): void
    {
        $model = $this->read('OrderLine', null);

        $this->assertStringContainsString('no migration history was read', (string) $this->pick($model, 'attribute', 'table')->note);
    }

    public function test_a_relation_named_by_class_is_resolved(): void
    {
        $customer = $this->fact('Order', 'relation', 'customer');

        $this->assertSame('belongsTo  App\Models\Customer', $customer->value);
        $this->assertSame(Confidence::Resolved, $customer->confidence);
    }

    public function test_a_relation_is_found_through_a_chained_call(): void
    {
        $this->assertSame('hasMany  App\Models\OrderLine', $this->fact('Order', 'relation', 'lines')->value);
    }

    public function test_a_method_that_is_not_a_relation_is_not_reported_as_one(): void
    {
        $names = array_map(
            static fn (ModelFact $fact): string => $fact->name,
            $this->read('Order')->group('relation'),
        );

        $this->assertNotContains('reference', $names);
    }

    public function test_a_polymorphic_relation_is_declined_with_its_reason(): void
    {
        $declined = $this->read('Comment')->declined;

        $this->assertCount(1, $declined);
        $this->assertSame('commentable', $declined[0]->name);
        $this->assertSame(Confidence::Textual, $declined[0]->confidence);
        $this->assertStringContainsString('morph map', (string) $declined[0]->note);
    }

    public function test_a_scope_from_a_trait_names_the_trait_that_registered_it(): void
    {
        $scope = $this->fact('Order', 'scope', 'App\Scopes\TenantScope');

        $this->assertStringContainsString('HasTenant', (string) $scope->note);
        $this->assertStringContainsString('Concerns/HasTenant.php', $scope->path);
    }

    public function test_a_named_closure_scope_is_declined_under_its_name(): void
    {
        $declined = array_values(array_filter(
            $this->read('Account')->declined,
            static fn (ModelFact $fact): bool => $fact->group === 'scope',
        ));

        $this->assertCount(1, $declined);
        $this->assertSame('ordered', $declined[0]->name);
        $this->assertStringContainsString('applied by a closure', (string) $declined[0]->note);
    }

    public function test_casts_carry_the_class_they_cast_to(): void
    {
        $this->assertSame('App\Models\OrderStatus', $this->fact('Order', 'casts', 'status')->value);
        $this->assertSame('datetime', $this->fact('Order', 'casts', 'placed_at')->value);
    }

    public function test_an_empty_guarded_is_reported_rather_than_omitted(): void
    {
        $guarded = $this->read('Customer')->group('guarded');

        $this->assertCount(1, $guarded);
        $this->assertStringContainsString('mass-assignable', $guarded[0]->name);
    }

    public function test_a_trait_is_reported_with_the_file_it_lives_in(): void
    {
        $traits = $this->read('Order')->group('trait');

        $this->assertSame(['App\Models\Concerns\HasTenant'], array_map(static fn (ModelFact $f): string => $f->name, $traits));
    }

    public function test_a_class_whose_ancestry_reaches_model_is_a_model(): void
    {
        $this->assertTrue($this->reader()->isModel('App\Models\Order'));
        $this->assertTrue($this->reader()->isModel('App\Models\Account'), 'reached through a framework base class');
        $this->assertFalse($this->reader()->isModel('App\Scopes\TenantScope'));
    }

    public function test_a_class_that_cannot_be_loaded_yields_nothing(): void
    {
        $this->assertNull($this->reader()->read('App\Models\NoSuchModel'));
    }

    /**
     * @param  ?array<string, true>  $schema
     */
    private function read(string $short, ?array $schema = ['order_lines' => true]): EloquentModel
    {
        $tables = $schema;
        $model = $this->reader()->read(
            'App\\Models\\'.$short,
            $tables === null ? null : static fn (): array => array_map(
                static fn (): Table => new Table('t'),
                $tables,
            ),
        );

        $this->assertInstanceOf(EloquentModel::class, $model, "could not read {$short}");

        return $model;
    }

    private function fact(string $short, string $group, string $name): ModelFact
    {
        return $this->pick($this->read($short), $group, $name);
    }

    private function pick(EloquentModel $model, string $group, string $name): ModelFact
    {
        foreach ($model->group($group) as $fact) {
            if ($fact->name === $name) {
                return $fact;
            }
        }

        $this->fail("no {$group} named {$name} on {$model->fqn}");
    }

    private function reader(): ModelReader
    {
        $project = Project::at(Cli::laravelRoot());
        $map = AutoloadMap::forProject($project);
        $graph = Index::open($project, $map)->graph(validate: false);

        return new ModelReader($graph, new ClassSource($project, $graph, new SymbolLocator($project, $map)));
    }
}

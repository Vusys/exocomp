<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Framework;

use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Framework\Laravel\Column;
use Vusys\Exocomp\Framework\Laravel\SchemaReplay;
use Vusys\Exocomp\Framework\Laravel\Table;
use Vusys\Exocomp\Tests\Support\Cli;

/**
 * The migration replay, asserted directly.
 *
 * The properties worth pinning here are the ones that make the reconstruction a reconstruction
 * rather than a listing: order of application, what a drop and a rename do to the state, and
 * what the reader admits it could not follow.
 */
final class SchemaReplayTest extends TestCase
{
    public function test_the_dump_is_the_state_the_migrations_build_on(): void
    {
        $orders = $this->table('shop_orders');

        // From the dump, then from a migration that ran after it.
        $this->assertArrayHasKey('tenant_id', $orders->columns);
        $this->assertArrayHasKey('total_pence', $orders->columns);
        $this->assertStringContainsString('mysql-schema.sql', (string) $orders->columns['tenant_id']->path);
        $this->assertStringContainsString('migrations', (string) $orders->columns['total_pence']->path);
    }

    public function test_a_migration_orders_by_filename_because_that_is_the_frameworks_rule(): void
    {
        // `total_pence` is added in February and the March migration renames a different
        // column. Applied out of order the rename would find nothing.
        $orders = $this->table('shop_orders');

        $this->assertArrayHasKey('note', $orders->columns);
        $this->assertArrayNotHasKey('internal_note', $orders->columns);
    }

    public function test_a_dropped_column_leaves_the_state(): void
    {
        $this->assertArrayNotHasKey('placed_at', $this->table('shop_orders')->columns);
    }

    public function test_a_renamed_column_keeps_its_type(): void
    {
        $this->assertSame('text', $this->column('shop_orders', 'note')->type);
    }

    public function test_a_column_added_by_a_migration_keeps_its_chained_modifiers(): void
    {
        $total = $this->column('shop_orders', 'total_pence');

        $this->assertSame('unsignedInteger', $total->type);
        $this->assertSame(['default(0)', "after('reference')"], $total->modifiers);
    }

    public function test_the_column_renders_its_type_and_modifiers_together(): void
    {
        $this->assertSame("unsignedInteger default(0) after('reference')", $this->column('shop_orders', 'total_pence')->render());
    }

    public function test_id_and_timestamps_and_soft_deletes_expand(): void
    {
        $customers = $this->table('customers');

        $this->assertSame(
            ['id', 'name', 'email', 'created_at', 'updated_at', 'deleted_at'],
            array_keys($customers->columns),
        );
    }

    public function test_a_sized_column_keeps_its_arguments(): void
    {
        $this->assertSame(['120'], $this->column('customers', 'name')->arguments);
        $this->assertSame('string(120)', $this->column('customers', 'name')->render());
    }

    public function test_morphs_expands_to_a_type_and_an_id(): void
    {
        $comments = $this->table('comments');

        $this->assertArrayHasKey('commentable_type', $comments->columns);
        $this->assertArrayHasKey('commentable_id', $comments->columns);
    }

    public function test_an_index_declared_by_a_migration_is_recorded_with_its_columns(): void
    {
        $indexes = $this->table('shop_orders')->indexes;
        $added = array_values(array_filter($indexes, static fn (array $index): bool => $index['columns'] === ['tenant_id', 'status']));

        $this->assertCount(1, $added);
        $this->assertSame('index', $added[0]['kind']);
    }

    public function test_a_unique_modifier_is_a_modifier_rather_than_an_index(): void
    {
        // `$table->string('email')->unique()` is a column with a modifier, not a bare index.
        $this->assertSame(['unique'], $this->column('customers', 'email')->modifiers);
    }

    public function test_a_table_whose_history_runs_raw_sql_is_incomplete(): void
    {
        $comments = $this->table('comments');

        $this->assertFalse($comments->isComplete());
        $this->assertStringContainsString('raw SQL', $comments->unread[0]['reason']);
    }

    public function test_a_table_with_a_readable_history_is_complete(): void
    {
        $this->assertTrue($this->table('customers')->isComplete());
    }

    public function test_every_migration_in_the_directory_is_read(): void
    {
        $this->assertSame(4, $this->replay()->migrationsRead());
        $this->assertSame(['database/schema/mysql-schema.sql'], $this->replay()->dumps());
    }

    public function test_tables_come_back_in_a_stable_order(): void
    {
        $this->assertSame(
            ['comments', 'customers', 'order_lines', 'people', 'shop_orders'],
            array_keys($this->replay()->tables()),
        );
    }

    public function test_a_directory_that_is_not_there_says_so_rather_than_reporting_nothing(): void
    {
        $replay = new SchemaReplay()->replay('/no/such/migrations', static fn (string $p): string => $p);

        $this->assertSame([], $replay->tables());
        $this->assertStringContainsString('is not there', $replay->notes()[0]);
    }

    public function test_an_index_written_on_a_column_is_an_index_of_the_table(): void
    {
        $indexes = array_map(
            static fn (array $index): string => $index['kind'].' '.implode(',', $index['columns']),
            $this->table('customers')->indexes,
        );

        $this->assertSame(['primary id', 'unique email'], $indexes);
    }

    private function replay(): SchemaReplay
    {
        $root = Cli::laravelRoot();

        return new SchemaReplay()
            ->withDump($root.'/database/schema/mysql-schema.sql', 'database/schema/mysql-schema.sql')
            ->replay(
                $root.'/database/migrations',
                static fn (string $absolute): string => 'database/migrations/'.basename($absolute),
            );
    }

    private function table(string $name): Table
    {
        $table = $this->replay()->table($name);

        $this->assertInstanceOf(Table::class, $table, "no table {$name}");

        return $table;
    }

    private function column(string $table, string $name): Column
    {
        $column = $this->table($table)->columns[$name] ?? null;

        $this->assertInstanceOf(Column::class, $column, "no column {$table}.{$name}");

        return $column;
    }
}

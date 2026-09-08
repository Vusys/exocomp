<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Framework;

use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Framework\Laravel\Column;
use Vusys\Exocomp\Framework\Laravel\SchemaDump;
use Vusys\Exocomp\Framework\Laravel\Table;
use Vusys\Exocomp\Tests\Support\Cli;

/**
 * The dump reader, asserted on directly rather than through a command.
 *
 * Every value it produces ends up in an address someone is expected to click, so the line
 * numbers are as much part of the answer as the column names. Reading the dump correctly and
 * reporting the wrong line for it is a different way of being confidently wrong.
 */
final class SchemaDumpTest extends TestCase
{
    public function test_columns_are_read_in_declaration_order(): void
    {
        $this->assertSame(
            ['id', 'tenant_id', 'customer_id', 'reference', 'status', 'internal_note', 'placed_at', 'created_at', 'updated_at'],
            array_keys($this->table('shop_orders')->columns),
        );
    }

    public function test_a_column_carries_the_line_it_was_declared_on(): void
    {
        // The dump's `CREATE TABLE` is line 4, so `id` is line 5. An off-by-one here sends
        // every reader of every `schema` answer one line away from what they asked about.
        $this->assertSame(5, $this->column('shop_orders', 'id')->line);
        $this->assertSame(13, $this->column('shop_orders', 'updated_at')->line);
    }

    public function test_the_table_carries_the_line_its_create_statement_is_on(): void
    {
        $this->assertSame(4, $this->table('shop_orders')->createdAtLine);
        $this->assertSame(19, $this->table('order_lines')->createdAtLine);
    }

    public function test_a_type_is_not_swallowed_by_the_modifier_that_follows_it(): void
    {
        // `json NOT NULL` read as a two-word type gives a column of type "json not" that is
        // nullable — wrong in both halves, and wrong quietly.
        $this->assertSame('bigint unsigned', $this->column('shop_orders', 'id')->type);
        $this->assertSame('varchar(64)', $this->column('shop_orders', 'reference')->type);
        $this->assertSame('text', $this->column('shop_orders', 'internal_note')->type);
    }

    public function test_nullability_comes_from_the_absence_of_not_null(): void
    {
        $this->assertSame([], $this->column('shop_orders', 'reference')->modifiers);
        $this->assertContains('nullable', $this->column('shop_orders', 'placed_at')->modifiers);
    }

    public function test_a_default_is_kept_and_a_null_default_is_not(): void
    {
        $this->assertContains("default('draft')", $this->column('shop_orders', 'status')->modifiers);
        $this->assertNotContains('default(NULL)', $this->column('shop_orders', 'placed_at')->modifiers);
    }

    public function test_auto_increment_is_reported(): void
    {
        $this->assertContains('auto_increment', $this->column('shop_orders', 'id')->modifiers);
    }

    public function test_character_sets_and_collations_are_dropped(): void
    {
        // They are on every column of a dump and say nothing about the column.
        $this->assertSame([], $this->column('shop_orders', 'reference')->modifiers);
    }

    public function test_index_kinds_are_distinguished(): void
    {
        $indexes = $this->table('shop_orders')->indexes;

        $this->assertSame(['primary', 'unique', 'index'], array_column($indexes, 'kind'));
        $this->assertSame([14, 15, 16], array_column($indexes, 'line'));
    }

    public function test_an_index_lists_the_columns_it_covers_and_not_its_own_name(): void
    {
        $unique = $this->table('shop_orders')->indexes[1];

        $this->assertSame(['reference'], $unique['columns']);
    }

    public function test_every_table_in_the_dump_is_found(): void
    {
        $this->assertSame(['shop_orders', 'order_lines', 'people'], array_keys($this->dump()));
    }

    public function test_a_file_that_is_not_there_yields_nothing_rather_than_failing(): void
    {
        $this->assertSame([], SchemaDump::read('/no/such/dump.sql', 'dump.sql'));
        $this->assertSame([], SchemaDump::dumpsIn('/no/such/directory'));
    }

    public function test_dumps_are_listed_in_a_stable_order(): void
    {
        $found = SchemaDump::dumpsIn(Cli::laravelRoot().'/database/schema');

        $this->assertCount(1, $found);
        $this->assertStringEndsWith('mysql-schema.sql', $found[0]);
    }

    /**
     * @return array<string, Table>
     */
    private function dump(): array
    {
        return SchemaDump::read(
            Cli::laravelRoot().'/database/schema/mysql-schema.sql',
            'database/schema/mysql-schema.sql',
        );
    }

    private function table(string $name): Table
    {
        $table = $this->dump()[$name] ?? null;

        $this->assertInstanceOf(Table::class, $table, "no table {$name} in the dump");

        return $table;
    }

    private function column(string $table, string $name): Column
    {
        $column = $this->table($table)->columns[$name] ?? null;

        $this->assertInstanceOf(Column::class, $column, "no column {$table}.{$name}");

        return $column;
    }
}

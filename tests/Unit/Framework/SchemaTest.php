<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Framework;

use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Tests\Support\Cli;

/**
 * Tier E — the framework profile's own tier, schema half.
 *
 * The cases that matter here are the ones where a plausible implementation is quietly wrong:
 * reading only `database/migrations` on an application whose history has been squashed, or
 * skipping a migration whose table name is a constant, both of which report a column as absent
 * with no indication that anything was missed.
 */
final class SchemaTest extends TestCase
{
    public function test_the_squashed_dump_is_the_base_the_migrations_build_on(): void
    {
        $run = Cli::inLaravel(['schema', 'shop_orders']);

        $this->assertSame(0, $run->exitCode);

        // From the dump.
        $this->assertStringContainsString('tenant_id', $run->output);
        // From a migration that ran after it.
        $this->assertStringContainsString('total_pence', $run->output);
        $this->assertStringContainsString('database/schema/mysql-schema.sql', $run->output);
    }

    public function test_a_dropped_column_is_gone_and_a_renamed_one_moves(): void
    {
        $run = Cli::inLaravel(['schema', 'shop_orders']);

        $this->assertStringNotContainsString('placed_at', $run->output);
        $this->assertStringNotContainsString('internal_note', $run->output);
        $this->assertStringContainsString('note ', $run->output);
    }

    public function test_a_table_named_by_a_constant_is_still_replayed(): void
    {
        // `Schema::table(self::TABLE, …)`. Reading the name as "not a literal" would drop the
        // whole migration, and the column it adds would be reported as absent.
        $this->assertStringContainsString('total_pence', Cli::inLaravel(['schema', 'shop_orders'])->output);
    }

    public function test_morphs_expands_to_both_columns(): void
    {
        $output = Cli::inLaravel(['schema', 'comments'])->output;

        $this->assertStringContainsString('commentable_type', $output);
        $this->assertStringContainsString('commentable_id', $output);
    }

    public function test_a_table_whose_history_includes_raw_sql_says_so(): void
    {
        $output = Cli::inLaravel(['schema', 'comments'])->output;

        $this->assertStringContainsString('not read', $output);
        $this->assertStringContainsString('raw SQL', $output);
    }

    public function test_a_complete_table_carries_no_unread_block(): void
    {
        $this->assertStringNotContainsString('not read', Cli::inLaravel(['schema', 'customers'])->output);
    }

    public function test_the_answer_always_says_where_it_came_from(): void
    {
        $this->assertStringContainsString(
            "the source's account of the schema, not the database's",
            Cli::inLaravel(['schema', 'customers'])->output,
        );
    }

    public function test_every_column_is_reported_as_inferred(): void
    {
        $run = Cli::inLaravel(['schema', 'customers', '--json']);

        foreach (explode("\n", trim($run->output)) as $line) {
            $record = json_decode($line, true);

            $this->assertIsArray($record);

            if (! isset($record['kind'])) {
                continue;
            }

            $this->assertSame('inferred', $record['confidence'], 'a reconstruction is never resolved');
        }
    }

    public function test_an_unknown_table_is_not_found_rather_than_empty(): void
    {
        $run = Cli::inLaravel(['schema', 'no_such_table']);

        $this->assertSame(1, $run->exitCode);
        $this->assertStringContainsString('no_such_table', $run->errors);
        $this->assertStringContainsString('migrations were read', $run->errors);
    }

    public function test_the_command_refuses_a_project_that_is_not_laravel(): void
    {
        $run = Cli::inCorpus(['schema', 'anything']);

        $this->assertSame(2, $run->exitCode);
        $this->assertStringContainsString('does not look like one', $run->errors);
    }

    public function test_a_revision_is_refused_rather_than_ignored(): void
    {
        $run = Cli::inLaravel(['schema', 'customers', '--rev', 'HEAD']);

        $this->assertSame(2, $run->exitCode);
        $this->assertStringContainsString('--rev is not available', $run->errors);
    }

    public function test_listing_every_table_marks_the_incomplete_ones(): void
    {
        $run = Cli::inLaravel(['schema']);

        $this->assertSame(0, $run->exitCode);
        $this->assertStringContainsString('comments', $run->output);
        $this->assertStringContainsString('(incomplete)', $run->output);
    }
}

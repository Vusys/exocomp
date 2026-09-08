<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Console\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Vusys\Exocomp\Console\CommandContext;
use Vusys\Exocomp\Console\FrameworkCommand;
use Vusys\Exocomp\Framework\Laravel\Column;
use Vusys\Exocomp\Framework\Laravel\SchemaReplay;
use Vusys\Exocomp\Framework\Laravel\Table;
use Vusys\Exocomp\Output\Address;
use Vusys\Exocomp\Output\Result;
use Vusys\Exocomp\Output\Row;
use Vusys\Exocomp\Output\Section;
use Vusys\Exocomp\Project\SymbolNotFound;
use Vusys\Exocomp\Symbol\Confidence;

/**
 * What a table looks like, reconstructed from the schema dump and the migrations after it.
 *
 * This answers 108 measured searches that were reading migration files by hand to work out
 * whether a column exists and what it holds — the `firstOrCreate`-versus-unique-index family of
 * question, where the answer decides whether a race condition is real.
 *
 * Nothing here is read from a database, and every row says so: a reconstruction is
 * {@see Confidence::Inferred} however carefully it is assembled, because a migration that was
 * never run, or a rollback, or a hand-applied change, are all invisible from the source. Where
 * the reader hit something it could not follow — raw SQL, most often — the table is reported as
 * incomplete rather than quietly short a column.
 */
#[AsCommand(name: 'schema', description: 'Reconstruct a table\'s columns and indexes from the migration history')]
final class SchemaCommand extends FrameworkCommand
{
    protected function configure(): void
    {
        parent::configure();

        $this->addArgument('table', InputArgument::OPTIONAL, 'The table to describe; omit to list every table the history creates');
    }

    protected function answer(InputInterface $input, CommandContext $context): Result
    {
        $this->refuseRevision($context, 'it replays the migration directory as it stands');

        $schema = $this->laravel($context)->schema($context->project);
        $name = $this->stringArgument($input, 'table');

        return $name === null
            ? $this->listTables($schema, $context)
            : $this->describe($schema, $name, $context);
    }

    private function describe(SchemaReplay $schema, string $name, CommandContext $context): Result
    {
        $table = $schema->table($name);

        if (! $table instanceof Table) {
            throw new SymbolNotFound($name, sprintf(
                'no migration in %s creates a table called "%s" — %d migrations were read%s',
                'database/migrations',
                $name,
                $schema->migrationsRead(),
                $schema->dumps() === [] ? ' and there is no squashed schema dump' : ', on top of '.implode(', ', $schema->dumps()),
            ));
        }

        $columns = array_values($table->columns);
        $addresses = array_map(
            static fn (Column $column): string => Address::of($column->path, $column->line),
            $columns,
        );
        $width = Address::width($addresses);

        [$kept, $elided] = $context->budget->apply(array_map(
            fn (Column $column): Row => $this->columnRow($table, $column, $width),
            $columns,
        ));

        $sections = [new Section(sprintf('columns of %s', $table->name), $kept)];

        if ($table->indexes !== []) {
            $sections[] = new Section('indexes', array_map(
                fn (array $index): Row => $this->indexRow($table, $index),
                $table->indexes,
            ));
        }

        return new Result(
            header: sprintf(
                '%s — %d column%s, %d index%s',
                $table->name,
                count($table->columns),
                count($table->columns) === 1 ? '' : 's',
                count($table->indexes),
                count($table->indexes) === 1 ? '' : 'es',
            ),
            sections: $sections,
            unresolvedTitle: 'not read',
            unresolved: array_map(
                static fn (array $entry): Row => new Row(
                    file: $entry['path'],
                    line: $entry['line'],
                    column: null,
                    fqn: null,
                    kind: 'migration',
                    text: Address::of($entry['path'], $entry['line']),
                    confidence: Confidence::Textual,
                    note: $entry['reason'],
                    carriesAddress: true,
                ),
                $table->unread,
            ),
            elided: $elided,
            notes: [...$this->provenance($schema), ...$schema->notes()],
        );
    }

    private function listTables(SchemaReplay $schema, CommandContext $context): Result
    {
        $tables = $schema->tables();
        $width = Address::width(array_map(
            static fn (Table $table): string => Address::of($table->createdIn ?? 'database/migrations', $table->createdAtLine === 0 ? null : $table->createdAtLine),
            array_values($tables),
        ));

        if ($tables === []) {
            throw new SymbolNotFound('schema', sprintf(
                'no tables could be reconstructed — %d migrations were read from database/migrations',
                $schema->migrationsRead(),
            ));
        }

        [$kept, $elided] = $context->budget->apply(array_map(
            fn (Table $table): Row => new Row(
                file: $table->createdIn ?? 'database/migrations',
                line: $table->createdAtLine === 0 ? null : $table->createdAtLine,
                column: null,
                fqn: null,
                kind: 'table',
                text: rtrim(sprintf(
                    '%s  %-40s %d columns%s',
                    str_pad(Address::of($table->createdIn ?? 'database/migrations', $table->createdAtLine === 0 ? null : $table->createdAtLine), $width),
                    $table->name,
                    count($table->columns),
                    $table->isComplete() ? '' : '   (incomplete)',
                )),
                confidence: Confidence::Inferred,
                extra: ['table' => $table->name, 'columns' => count($table->columns), 'complete' => $table->isComplete()],
                carriesAddress: true,
            ),
            array_values($tables),
        ));

        return new Result(
            header: sprintf('%d tables', count($tables)),
            sections: [new Section('', $kept)],
            elided: $elided,
            notes: [...$this->provenance($schema), ...$schema->notes()],
        );
    }

    private function columnRow(Table $table, Column $column, int $width): Row
    {
        return new Row(
            file: $column->path,
            line: $column->line,
            column: null,
            fqn: null,
            kind: 'column',
            text: rtrim(str_pad(Address::of($column->path, $column->line), $width).'  '.str_pad($column->name, 32).' '.$column->render()),
            confidence: Confidence::Inferred,
            extra: ['table' => $table->name, 'column' => $column->name, 'type' => $column->type, 'modifiers' => implode(' ', $column->modifiers)],
            carriesAddress: true,
        );
    }

    /**
     * @param  array{kind: string, columns: list<string>, path: string, line: int}  $index
     */
    private function indexRow(Table $table, array $index): Row
    {
        return new Row(
            file: $index['path'],
            line: $index['line'],
            column: null,
            fqn: null,
            kind: 'index',
            text: sprintf('%s  %-10s %s', Address::of($index['path'], $index['line']), $index['kind'], implode(', ', $index['columns'])),
            confidence: Confidence::Inferred,
            extra: ['table' => $table->name, 'index' => $index['kind'], 'columns' => implode(',', $index['columns'])],
            carriesAddress: true,
        );
    }

    /**
     * Where the answer came from, stated every time.
     *
     * @return list<string>
     */
    private function provenance(SchemaReplay $schema): array
    {
        $sources = $schema->dumps();
        $sources[] = sprintf('%d migrations', $schema->migrationsRead());

        return [sprintf(
            'reconstructed from %s; this is the source\'s account of the schema, not the database\'s',
            implode(' then ', $sources),
        )];
    }
}

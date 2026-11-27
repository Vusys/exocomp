<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Framework\Laravel;

/**
 * A table's reconstructed shape, and how much of its history could be read.
 *
 * The unread list is the important half. A table whose migrations include raw SQL, or a
 * conditional the reader cannot evaluate, has a shape that is a best reading rather than a
 * fact — and saying so is the difference between a useful reconstruction and a confident
 * fiction about what is in the database.
 */
final class Table
{
    /** @var array<string, Column> */
    public array $columns = [];

    /** @var list<array{kind: string, columns: list<string>, path: string, line: int, name?: string}> */
    public array $indexes = [];

    /** @var list<array{reason: string, path: string, line: int}> */
    public array $unread = [];

    /** @var list<string> */
    public array $migrations = [];

    public function __construct(
        public string $name,
        public ?string $connection = null,
        public ?string $createdIn = null,
        public int $createdAtLine = 0,
    ) {}

    public function add(Column $column): void
    {
        $this->columns[$column->name] = $column;
    }

    public function drop(string $name): void
    {
        unset($this->columns[$name]);
    }

    /**
     * `dropIndex('orders_status_index')` or `dropIndex(['status'])`: an index named so, or the
     * one on exactly those columns. An index given no name has the one the framework makes up,
     * `{table}_{columns}_{kind}`.
     *
     * @param  list<string>  $columns
     */
    public function dropIndex(?string $name, array $columns, string $kind): void
    {
        $this->indexes = array_values(array_filter($this->indexes, function (array $index) use ($name, $columns, $kind): bool {
            if ($kind !== '' && $index['kind'] !== $kind) {
                return true;
            }

            if ($name !== null) {
                return ($index['name'] ?? $this->indexName($index['columns'], $index['kind'])) !== $name;
            }

            return $index['columns'] !== $columns;
        }));
    }

    /**
     * @param  list<string>  $columns
     */
    public function indexName(array $columns, string $kind): string
    {
        return str_replace(['-', '.'], '_', strtolower($this->name.'_'.implode('_', $columns).'_'.$kind));
    }

    public function rename(string $from, string $to): void
    {
        $column = $this->columns[$from] ?? null;

        if (! $column instanceof Column) {
            return;
        }

        unset($this->columns[$from]);

        $this->columns[$to] = new Column($to, $column->type, $column->arguments, $column->modifiers, $column->path, $column->line);
    }

    public function isComplete(): bool
    {
        return $this->unread === [];
    }
}

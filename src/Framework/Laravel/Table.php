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
        // A changed column keeps its place; a new one goes where `after('x')` or `first()` puts
        // it, as MySQL places it, and otherwise at the end.
        $place = isset($this->columns[$column->name]) ? null : $this->placement($column->modifiers);

        if ($place === null) {
            $this->columns[$column->name] = $column;

            return;
        }

        $position = $place === '' ? 0 : array_search($place, array_keys($this->columns), true);

        if ($position === false) {
            $this->columns[$column->name] = $column;

            return;
        }

        $offset = $place === '' ? 0 : $position + 1;
        $this->columns = array_slice($this->columns, 0, $offset, true) + [$column->name => $column] + array_slice($this->columns, $offset, null, true);
    }

    /**
     * The column a new one is placed after, '' for `first()`, or null for the end.
     *
     * @param  list<string>  $modifiers
     */
    private function placement(array $modifiers): ?string
    {
        foreach ($modifiers as $modifier) {
            if ($modifier === 'first') {
                return '';
            }

            if (preg_match("/^after\\('([^']+)'\\)$/", $modifier, $match) === 1) {
                return $match[1];
            }
        }

        return null;
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

        // Renamed in place: a rename does not move the column.
        $renamed = [];

        foreach ($this->columns as $name => $existing) {
            if ($name === $from) {
                $renamed[$to] = new Column($to, $column->type, $column->arguments, $column->modifiers, $column->path, $column->line);
            } elseif ($name !== $to) {
                $renamed[$name] = $existing;
            }
        }

        $this->columns = $renamed;
    }

    public function isComplete(): bool
    {
        return $this->unread === [];
    }
}

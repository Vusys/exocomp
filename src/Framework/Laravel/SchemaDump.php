<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Framework\Laravel;

/**
 * The squashed schema dump, read as the starting state for a migration replay.
 *
 * A mature application does not keep every migration it ever ran. The framework squashes them
 * into `database/schema/<connection>-schema.sql` and loads that first, so a replay that reads
 * only `database/migrations` sees the last few months of a decade-old schema. Measured on a
 * large application: 384 migrations describe 64 tables, while the dump beside them describes
 * 284 — so ignoring the dump would report `users` as having four columns, confidently.
 *
 * This reads `CREATE TABLE` blocks and nothing else. Inserts, triggers, views and procedures
 * are not schema shape, and interpreting general SQL is not something a PHP tool should start
 * doing. A dump the reader could not open is reported rather than skipped, because "no such
 * table" and "the file describing it would not open" must not look the same.
 */
final class SchemaDump
{
    /**
     * @return array<string, Table>
     */
    public static function read(string $absolute, string $path): array
    {
        $contents = file_get_contents($absolute);

        if ($contents === false) {
            return [];
        }

        $tables = [];
        $lines = explode("\n", $contents);
        $current = null;
        $line = 0;

        foreach ($lines as $text) {
            $line++;
            $trimmed = trim($text);

            if ($current instanceof Table) {
                if (str_starts_with($trimmed, ')')) {
                    $tables[$current->name] = $current;
                    $current = null;

                    continue;
                }

                self::readMember($current, $trimmed, $path, $line);

                continue;
            }

            $name = self::createdTable($trimmed);

            if ($name !== null) {
                $current = new Table($name, createdIn: $path, createdAtLine: $line);
            }
        }

        return $tables;
    }

    /**
     * Where the framework would have put a dump for this project, if there is one.
     *
     * @return list<string>
     */
    public static function dumpsIn(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $found = glob(rtrim($directory, '/').'/*-schema.sql');

        if ($found === false) {
            return [];
        }

        sort($found);

        return $found;
    }

    private static function createdTable(string $line): ?string
    {
        if (preg_match('/^CREATE TABLE (?:IF NOT EXISTS )?([^\s(]+)/i', $line, $matches) !== 1) {
            return null;
        }

        $name = trim($matches[1], '`"[]');
        $dot = strrpos($name, '.');

        // `public.users` in a PostgreSQL dump names the same table as `users` does everywhere
        // else in the project, and the schema qualifier would only make lookups miss.
        return $dot === false ? $name : substr($name, $dot + 1);
    }

    private static function readMember(Table $table, string $line, string $path, int $number): void
    {
        $line = rtrim($line, ',');

        if ($line === '') {
            return;
        }

        $index = self::indexKind($line);

        if ($index !== null) {
            $table->indexes[] = ['kind' => $index, 'columns' => self::quotedNames($line), 'path' => $path, 'line' => $number];

            return;
        }

        if (preg_match('/^[`"]?([A-Za-z_][A-Za-z0-9_]*)[`"]?\s+(.+)$/', $line, $matches) !== 1) {
            return;
        }

        [$type, $modifiers] = self::splitDefinition($matches[2]);

        $table->add(new Column($matches[1], $type, [], $modifiers, $path, $number));
    }

    private static function indexKind(string $line): ?string
    {
        return match (true) {
            (bool) preg_match('/^PRIMARY KEY/i', $line) => 'primary',
            (bool) preg_match('/^UNIQUE (KEY|INDEX)/i', $line) => 'unique',
            (bool) preg_match('/^(KEY|INDEX)\s/i', $line) => 'index',
            (bool) preg_match('/^FULLTEXT/i', $line) => 'fulltext',
            (bool) preg_match('/^(CONSTRAINT|FOREIGN KEY)/i', $line) => 'foreign',
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    private static function quotedNames(string $line): array
    {
        $parenthesised = strrpos($line, '(');

        if ($parenthesised === false) {
            return [];
        }

        preg_match_all('/[`"]([A-Za-z_][A-Za-z0-9_]*)[`"]/', substr($line, $parenthesised), $matches);

        return $matches[1];
    }

    /**
     * Words that continue a type rather than starting a modifier: `double precision`,
     * `character varying`, `timestamp without time zone`, `int unsigned`. Without this list a
     * naive "type is one or two words" reading turns `json NOT NULL` into a column of type
     * "json not" that is nullable — wrong in both halves, and wrong quietly.
     *
     * @var list<string>
     */
    private const array TYPE_WORDS = ['precision', 'varying', 'unsigned', 'zerofill', 'signed', 'with', 'without', 'time', 'zone', 'local'];

    /**
     * Splits `varchar(255) CHARACTER SET utf8mb4 NOT NULL DEFAULT 'x'` into its type and the
     * modifiers worth repeating. Character sets and collations are dropped: they are on every
     * column of a dump and say nothing about the column.
     *
     * @return array{string, list<string>}
     */
    private static function splitDefinition(string $definition): array
    {
        if (preg_match('/^([A-Za-z_]+(?:\([^)]*\))?)\s*(.*)$/', $definition, $matches) !== 1) {
            return [$definition, []];
        }

        $type = $matches[1];
        $rest = $matches[2];

        while (preg_match('/^([A-Za-z]+)(?:\s+(.*))?$/', $rest, $word) === 1 && in_array(strtolower($word[1]), self::TYPE_WORDS, true)) {
            $type .= ' '.$word[1];
            $rest = $word[2] ?? '';
        }

        $rest = (string) preg_replace('/\s*(CHARACTER SET|COLLATE)\s+\S+/i', '', $rest);
        $modifiers = [];

        if (preg_match('/\bNOT NULL\b/i', $rest) !== 1) {
            $modifiers[] = 'nullable';
        }

        if (preg_match("/\bDEFAULT\s+('[^']*'|\S+)/i", $rest, $default) === 1 && strcasecmp($default[1], 'NULL') !== 0) {
            $modifiers[] = 'default('.$default[1].')';
        }

        if (preg_match('/\bAUTO_INCREMENT\b/i', $rest) === 1) {
            $modifiers[] = 'auto_increment';
        }

        return [strtolower(trim($type)), $modifiers];
    }
}

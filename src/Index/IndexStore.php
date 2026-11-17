<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Index;

use PDO;
use PDOStatement;
use RuntimeException;
use Vusys\Exocomp\Symbol\Identifier;
use Vusys\Exocomp\Symbol\SymbolKind;

/**
 * The SQLite cache: what is stored, and how it is asked.
 *
 * Rows are keyed by content hash rather than by path (see {@see FileFacts}), and every read
 * joins through the `files` table for one project root — so the store holds facts about bytes
 * and the join decides which of those bytes are currently checked out where.
 */
final class IndexStore
{
    private readonly PDO $connection;

    /** @var array<string, PDOStatement> */
    private array $prepared = [];

    public function __construct(public readonly string $path)
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! @mkdir($directory, 0o777, true) && ! is_dir($directory)) {
            throw new RuntimeException("could not create the cache directory \"{$directory}\"");
        }

        $this->connection = new PDO('sqlite:'.$path, options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        IndexSchema::apply($this->connection);
    }

    /**
     * Takes the write lock up front. A batch reads before it writes, and a deferred `BEGIN` would
     * then have to upgrade its read to a write; in WAL mode that upgrade fails at once with
     * "database is locked" if another process committed in between, without waiting out the
     * busy timeout. The cache is shared by every project, so two concurrent runs hit that easily.
     */
    public function beginBatch(): void
    {
        $this->connection->exec('BEGIN IMMEDIATE');
    }

    public function commitBatch(): void
    {
        if ($this->connection->inTransaction()) {
            $this->connection->commit();
        }
    }

    public function hasBlob(string $hash): bool
    {
        $statement = $this->prepare('has_blob', 'SELECT 1 FROM blobs WHERE hash = ? LIMIT 1');
        $statement->execute([$hash]);

        return $statement->fetchColumn() !== false;
    }

    public function putBlob(string $hash, FileFacts $facts): void
    {
        $this->prepare('put_blob', 'INSERT OR IGNORE INTO blobs (hash, namespaces) VALUES (?, ?)')
            ->execute([$hash, implode("\n", $facts->namespaces)]);

        $symbol = $this->prepare('put_symbol', <<<'SQL'
            INSERT INTO symbols (hash, fqn, lower_fqn, name, lower_name, kind, owner, lower_owner,
                                 line, end_line, col, span_line, signature, full_signature,
                                 modifiers, promoted, has_body, type_fqn, inferred_fqn, inferred_reason,
                                 inferred_elements)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            SQL);

        foreach ($facts->symbols as $row) {
            $kind = $this->text($row['kind']);
            $fqn = $this->text($row['fqn']);
            $name = $this->text($row['name']);
            $owner = isset($row['owner']) && is_string($row['owner']) ? $row['owner'] : null;
            $foldable = FileFacts::caseInsensitive($kind);

            $symbol->execute([
                $hash,
                $fqn,
                $foldable ? Identifier::fold($fqn) : null,
                $name,
                $foldable ? Identifier::fold($name) : null,
                $kind,
                $owner,
                $owner === null ? null : Identifier::fold($owner),
                $this->number($row['line']),
                $this->number($row['end_line']),
                $this->number($row['col']),
                $this->number($row['span_line']),
                $this->text($row['signature']),
                $this->text($row['full_signature']),
                $this->text($row['modifiers']),
                ($row['promoted'] ?? false) === true ? 1 : 0,
                ($row['has_body'] ?? false) === true ? 1 : 0,
                isset($row['type_fqn']) && is_string($row['type_fqn']) ? $row['type_fqn'] : null,
                isset($row['inferred_fqn']) && is_string($row['inferred_fqn']) ? $row['inferred_fqn'] : null,
                isset($row['inferred_reason']) && is_string($row['inferred_reason']) ? $row['inferred_reason'] : null,
                isset($row['inferred_elements']) && is_string($row['inferred_elements']) ? $row['inferred_elements'] : null,
            ]);
        }

        $name = $this->prepare('put_name', 'INSERT INTO names (hash, lower_name) VALUES (?, ?)');
        $seen = [];

        // Declared names count as mentions. Without them, looking for references to a method
        // that is declared but never called would not even open the file that declares it —
        // and the declaration is the first thing a caller asking about a member wants.
        $mentioned = $facts->referencedNames;

        foreach ($facts->symbols as $symbol) {
            $mentioned[] = self::text($symbol['name']);
        }

        foreach ($mentioned as $referenced) {
            $lower = Identifier::fold($referenced);

            if (isset($seen[$lower])) {
                continue;
            }

            $seen[$lower] = true;
            $name->execute([$hash, $lower]);
        }

        $edge = $this->prepare('put_edge', 'INSERT INTO edges (hash, from_fqn, lower_from, kind, to_fqn, lower_to) VALUES (?, ?, ?, ?, ?, ?)');

        foreach ($facts->edges as $row) {
            $edge->execute([$hash, $row['from'], Identifier::fold($row['from']), $row['kind'], $row['to'], Identifier::fold($row['to'])]);
        }
    }

    public function putFile(string $root, string $path, string $hash, int $mtime, int $size): void
    {
        $this->prepare('put_file', 'INSERT OR REPLACE INTO files (root, path, hash, mtime, size) VALUES (?, ?, ?, ?, ?)')
            ->execute([$root, $path, $hash, $mtime, $size]);
    }

    /**
     * @return array<string, array{hash: string, mtime: int, size: int}>
     */
    public function filesIn(string $root): array
    {
        $statement = $this->prepare('files_in', 'SELECT path, hash, mtime, size FROM files WHERE root = ?');
        $statement->execute([$root]);

        $files = [];

        foreach ($statement->fetchAll() as $row) {
            if (! is_array($row)) {
                continue;
            }

            $files[$this->text($row['path'])] = [
                'hash' => $this->text($row['hash']),
                'mtime' => $this->number($row['mtime']),
                'size' => $this->number($row['size']),
            ];
        }

        return $files;
    }

    /**
     * A file that will not parse is remembered along with the stamp it failed at, so the next
     * run neither re-parses it nor re-announces it. It is retried the moment its bytes change,
     * which is the only thing that could alter the outcome.
     *
     * @return array<string, array{mtime: int, size: int, line: int, message: string}>
     */
    public function failuresIn(string $root): array
    {
        $statement = $this->prepare('failures_in', 'SELECT path, mtime, size, line, message FROM failures WHERE root = ?');
        $statement->execute([$root]);

        $failures = [];

        foreach ($statement->fetchAll() as $row) {
            if (! is_array($row)) {
                continue;
            }

            $failures[$this->text($row['path'])] = [
                'mtime' => $this->number($row['mtime']),
                'size' => $this->number($row['size']),
                'line' => $this->number($row['line']),
                'message' => $this->text($row['message']),
            ];
        }

        return $failures;
    }

    public function putFailure(string $root, string $path, int $mtime, int $size, int $line, string $message): void
    {
        $this->prepare('put_failure', 'INSERT OR REPLACE INTO failures (root, path, mtime, size, line, message) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$root, $path, $mtime, $size, $line, $message]);
    }

    public function forgetFailure(string $root, string $path): void
    {
        $this->prepare('forget_failure', 'DELETE FROM failures WHERE root = ? AND path = ?')->execute([$root, $path]);
    }

    /**
     * The recorded size and mtime for a handful of paths — the cheap half of validating an
     * answer without touching the rest of the corpus.
     *
     * @param  list<string>  $paths
     * @return array<string, array{mtime: int, size: int}>
     */
    public function stampsFor(string $root, array $paths): array
    {
        if ($paths === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($paths), '?'));
        $statement = $this->connection->prepare(
            "SELECT path, mtime, size FROM files WHERE root = ? AND path IN ({$placeholders})",
        );
        $statement->execute([$root, ...$paths]);

        $stamps = [];

        foreach ($statement->fetchAll() as $row) {
            if (is_array($row)) {
                $stamps[$this->text($row['path'])] = [
                    'mtime' => $this->number($row['mtime']),
                    'size' => $this->number($row['size']),
                ];
            }
        }

        return $stamps;
    }

    /**
     * Every indexed path for a root, optionally narrowed to files declaring a symbol under one
     * of a set of namespace prefixes.
     *
     * The narrowing is what makes `--in` a way of making a corpus-wide query cheap rather than
     * merely of filtering its results: on a large codebase, most of the cost of a structural
     * query is opening and parsing files, and a scoped query should not pay for the ones it
     * could never match.
     *
     * @param  list<string>  $namespacePrefixes
     * @return list<string>
     */
    public function paths(string $root, array $namespacePrefixes = []): array
    {
        $sql = 'SELECT DISTINCT f.path FROM files f';
        $parameters = [$root];

        if ($namespacePrefixes !== []) {
            $clauses = [];

            foreach ($namespacePrefixes as $index => $prefix) {
                $clauses[] = "s.fqn LIKE :prefix{$index}";
                $parameters['prefix'.$index] = $prefix.'%';
            }

            $sql .= ' JOIN symbols s ON s.hash = f.hash AND ('.implode(' OR ', $clauses).')';
        }

        $sql .= ' WHERE f.root = ? ORDER BY f.path';

        // Positional and named parameters cannot be mixed, so the root is bound by name too
        // once prefixes are in play.
        if ($namespacePrefixes !== []) {
            $sql = str_replace('f.root = ?', 'f.root = :root', $sql);
            $parameters['root'] = $root;
            unset($parameters[0]);
        }

        $statement = $this->connection->prepare($sql);
        $statement->execute($parameters);

        $paths = [];

        foreach ($statement->fetchAll() as $row) {
            if (is_array($row)) {
                $paths[] = self::text($row['path']);
            }
        }

        return $paths;
    }

    /**
     * Paths whose contents mention every one of these names.
     *
     * This is the filter that makes a structural query affordable. A pattern naming a method
     * cannot match in a file that never mentions it, so the corpus shrinks to the handful of
     * files worth parsing before a parser is started at all — six and a half thousand files
     * down to three, in the case that motivated building this.
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    public function pathsMentioning(string $root, array $names): array
    {
        if ($names === []) {
            return $this->paths($root);
        }

        // Driven from the first name rather than from the root: a root holds every file, a name
        // a handful. See self::selection() for why the order is spelled out.
        $sql = 'SELECT DISTINCT f.path FROM names n0 CROSS JOIN files f ON f.hash = n0.hash AND f.root = :root';
        $parameters = ['root' => $root, 'name0' => Identifier::fold($names[0])];

        foreach (array_slice($names, 1, null, true) as $index => $name) {
            $sql .= " CROSS JOIN names n{$index} ON n{$index}.hash = f.hash AND n{$index}.lower_name = :name{$index}";
            $parameters['name'.$index] = Identifier::fold($name);
        }

        $sql .= ' WHERE n0.lower_name = :name0 ORDER BY f.path';

        $statement = $this->connection->prepare($sql);
        $statement->execute($parameters);

        $paths = [];

        foreach ($statement->fetchAll() as $row) {
            if (is_array($row)) {
                $paths[] = self::text($row['path']);
            }
        }

        return $paths;
    }

    public function forgetFile(string $root, string $path): void
    {
        $this->prepare('forget_file', 'DELETE FROM files WHERE root = ? AND path = ?')->execute([$root, $path]);
    }

    /**
     * Class-like and function names fold case, because PHP does; everything else matches
     * exactly, because PHP does not.
     *
     * @return list<SymbolRecord>
     */
    public function byName(string $root, string $name, ?SymbolKind $kind = null): array
    {
        $sql = $this->selection().' AND (s.lower_name = :folded OR s.name = :exact)';
        $parameters = ['root' => $root, 'folded' => Identifier::fold($name), 'exact' => $name];

        if ($kind instanceof SymbolKind) {
            $sql .= ' AND s.kind = :kind';
            $parameters['kind'] = $kind->value;
        }

        return $this->records($sql.' ORDER BY f.path, s.line', $parameters);
    }

    /**
     * @return list<SymbolRecord>
     */
    public function byFqn(string $root, string $fqn): array
    {
        return $this->records(
            $this->selection().' AND (s.lower_fqn = :folded OR s.fqn = :exact) ORDER BY f.path, s.line',
            ['root' => $root, 'folded' => Identifier::fold($fqn), 'exact' => $fqn],
        );
    }

    /**
     * @return list<SymbolRecord>
     */
    public function membersOf(string $root, string $ownerFqn): array
    {
        return $this->records(
            $this->selection().' AND s.lower_owner = :owner ORDER BY s.line',
            ['root' => $root, 'owner' => Identifier::fold($ownerFqn)],
        );
    }

    /**
     * Names that declare an edge of this kind pointing at the given target — the subclasses of
     * a class, the implementors of an interface, the users of a trait.
     *
     * @return list<SymbolRecord>
     */
    public function edgesTo(string $root, string $kind, string $target): array
    {
        return $this->records(
            'SELECT f.path, s.* FROM edges e'
            .' CROSS JOIN files f ON f.hash = e.hash AND f.root = :root'
            .' CROSS JOIN symbols s ON s.hash = e.hash AND s.lower_fqn = e.lower_from'
            .' WHERE e.kind = :kind AND e.lower_to = :target'
            .' ORDER BY f.path, s.line',
            ['root' => $root, 'kind' => $kind, 'target' => Identifier::fold($target)],
        );
    }

    /**
     * @return list<array{kind: string, to: string}>
     */
    public function edgesFrom(string $root, string $fqn): array
    {
        $statement = $this->prepare('edges_from',
            'SELECT DISTINCT e.kind, e.to_fqn FROM edges e'
            .' JOIN files f ON f.hash = e.hash AND f.root = ?'
            .' WHERE e.lower_from = ?');
        $statement->execute([$root, Identifier::fold($fqn)]);

        $edges = [];

        foreach ($statement->fetchAll() as $row) {
            if (is_array($row)) {
                $edges[] = ['kind' => $this->text($row['kind']), 'to' => $this->text($row['to_fqn'])];
            }
        }

        return $edges;
    }

    /**
     * @return array{files: int, symbols: int, edges: int, blobs: int, failures: int, shared: int}
     */
    public function statistics(string $root): array
    {
        return [
            'files' => $this->count('SELECT COUNT(*) FROM files WHERE root = ?', [$root]),
            'symbols' => $this->count('SELECT COUNT(*) FROM symbols s JOIN files f ON f.hash = s.hash WHERE f.root = ?', [$root]),
            'edges' => $this->count('SELECT COUNT(*) FROM edges e JOIN files f ON f.hash = e.hash WHERE f.root = ?', [$root]),
            'blobs' => $this->count('SELECT COUNT(*) FROM blobs', []),
            // Content this root shares with at least one other checkout — the measure of
            // whether the content-addressed design is actually paying for itself.
            'failures' => $this->count('SELECT COUNT(*) FROM failures WHERE root = ?', [$root]),
            'shared' => $this->count(
                'SELECT COUNT(*) FROM files f WHERE f.root = ? AND EXISTS ('
                .'SELECT 1 FROM files o WHERE o.hash = f.hash AND o.root <> f.root)',
                [$root],
            ),
        ];
    }

    /**
     * Symbols first, then the file each sits in — never the other way round.
     *
     * Every lookup here narrows on a symbol column to a few rows, and the root narrows to every
     * file in the project. Left to choose, SQLite's planner had no statistics to tell those apart
     * and drove from the root, so each lookup walked the whole corpus: ~45 ms apiece on a
     * 7,000-file application, which a reference search asks thousands of times. `CROSS JOIN` is
     * SQLite's way of fixing the order, and unlike planner statistics it holds on a cache that
     * has only just been built.
     */
    private function selection(): string
    {
        return 'SELECT f.path, s.* FROM symbols s CROSS JOIN files f ON f.hash = s.hash AND f.root = :root WHERE 1 = 1';
    }

    /**
     * @param  array<string, string>  $parameters
     * @return list<SymbolRecord>
     */
    private function records(string $sql, array $parameters): array
    {
        $statement = $this->connection->prepare($sql);
        $statement->execute($parameters);

        $records = [];

        foreach ($statement->fetchAll() as $row) {
            if (! is_array($row)) {
                continue;
            }

            $records[] = new SymbolRecord(
                path: $this->text($row['path']),
                fqn: $this->text($row['fqn']),
                name: $this->text($row['name']),
                kind: SymbolKind::from($this->text($row['kind'])),
                owner: isset($row['owner']) && is_string($row['owner']) ? $row['owner'] : null,
                line: $this->number($row['line']),
                endLine: $this->number($row['end_line']),
                column: $this->number($row['col']),
                signature: $this->text($row['signature']),
                fullSignature: $this->text($row['full_signature']),
                modifiers: $this->text($row['modifiers']),
                isPromoted: $this->number($row['promoted']) === 1,
                hasBody: $this->number($row['has_body']) === 1,
                typeFqn: isset($row['type_fqn']) && is_string($row['type_fqn']) ? $row['type_fqn'] : null,
                inferredFqn: isset($row['inferred_fqn']) && is_string($row['inferred_fqn']) ? $row['inferred_fqn'] : null,
                inferredReason: isset($row['inferred_reason']) && is_string($row['inferred_reason']) ? $row['inferred_reason'] : null,
                inferredElements: $this->elements($row['inferred_elements'] ?? null),
            );
        }

        return $records;
    }

    /**
     * @return array<string, string>
     */
    private function elements(mixed $json): array
    {
        $decoded = is_string($json) ? json_decode($json, true) : null;

        return is_array($decoded) ? array_filter(array_map(static fn (mixed $fqn): string => is_string($fqn) ? $fqn : '', $decoded), static fn (string $fqn): bool => $fqn !== '') : [];
    }

    /**
     * @param  list<string>  $parameters
     */
    private function count(string $sql, array $parameters): int
    {
        $statement = $this->connection->prepare($sql);
        $statement->execute($parameters);

        return (int) $statement->fetchColumn();
    }

    private function prepare(string $key, string $sql): PDOStatement
    {
        return $this->prepared[$key] ??= $this->connection->prepare($sql);
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function number(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}

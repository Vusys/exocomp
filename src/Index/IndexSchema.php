<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Index;

use PDO;

/**
 * The cache's shape, and the rule for throwing it away.
 *
 * A stale index is worse than no index, because it is confidently wrong (REPORT §8). Two
 * defences sit here. The identity of the cache includes the tool and parser versions, so an
 * upgrade that changes what a signature looks like invalidates everything rather than mixing
 * old rows with new. And facts hang off a content hash rather than a path, so a file whose
 * bytes changed cannot serve its old answers — there is nothing to invalidate, only a hash that
 * no longer matches.
 */
final class IndexSchema
{
    public const int VERSION = 4;

    public static function apply(PDO $connection): void
    {
        $connection->exec('PRAGMA journal_mode = WAL');
        $connection->exec('PRAGMA synchronous = NORMAL');

        $connection->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS blobs (
                hash TEXT PRIMARY KEY,
                namespaces TEXT NOT NULL
            );

            CREATE TABLE IF NOT EXISTS files (
                root TEXT NOT NULL,
                path TEXT NOT NULL,
                hash TEXT NOT NULL,
                mtime INTEGER NOT NULL,
                size INTEGER NOT NULL,
                PRIMARY KEY (root, path)
            );

            CREATE INDEX IF NOT EXISTS files_hash ON files (hash);

            CREATE TABLE IF NOT EXISTS names (
                hash TEXT NOT NULL,
                lower_name TEXT NOT NULL
            );

            CREATE INDEX IF NOT EXISTS names_lower ON names (lower_name);
            CREATE INDEX IF NOT EXISTS names_hash ON names (hash);

            CREATE TABLE IF NOT EXISTS failures (
                root TEXT NOT NULL,
                path TEXT NOT NULL,
                mtime INTEGER NOT NULL,
                size INTEGER NOT NULL,
                line INTEGER NOT NULL,
                message TEXT NOT NULL,
                PRIMARY KEY (root, path)
            );

            CREATE TABLE IF NOT EXISTS symbols (
                hash TEXT NOT NULL,
                fqn TEXT NOT NULL,
                lower_fqn TEXT,
                name TEXT NOT NULL,
                lower_name TEXT,
                kind TEXT NOT NULL,
                owner TEXT,
                lower_owner TEXT,
                line INTEGER NOT NULL,
                end_line INTEGER NOT NULL,
                col INTEGER NOT NULL,
                span_line INTEGER NOT NULL,
                signature TEXT NOT NULL,
                full_signature TEXT NOT NULL,
                modifiers TEXT NOT NULL,
                promoted INTEGER NOT NULL,
                has_body INTEGER NOT NULL,
                type_fqn TEXT
            );

            CREATE INDEX IF NOT EXISTS symbols_hash ON symbols (hash);
            CREATE INDEX IF NOT EXISTS symbols_lower_fqn ON symbols (lower_fqn);
            CREATE INDEX IF NOT EXISTS symbols_lower_name ON symbols (lower_name);
            CREATE INDEX IF NOT EXISTS symbols_name ON symbols (name);
            CREATE INDEX IF NOT EXISTS symbols_lower_owner ON symbols (lower_owner);

            CREATE TABLE IF NOT EXISTS edges (
                hash TEXT NOT NULL,
                from_fqn TEXT NOT NULL,
                lower_from TEXT NOT NULL,
                kind TEXT NOT NULL,
                to_fqn TEXT NOT NULL,
                lower_to TEXT NOT NULL
            );

            CREATE INDEX IF NOT EXISTS edges_hash ON edges (hash);
            CREATE INDEX IF NOT EXISTS edges_lower_to ON edges (lower_to, kind);
            CREATE INDEX IF NOT EXISTS edges_lower_from ON edges (lower_from);
            SQL);
    }

    /**
     * The cache file's name carries everything that would invalidate it, so a mismatch is a
     * different file rather than a migration.
     */
    public static function fileName(string $toolVersion, string $parserVersion): string
    {
        return sprintf('index-v%d-%s-%s.sqlite', self::VERSION, self::slug($toolVersion), self::slug($parserVersion));
    }

    private static function slug(string $value): string
    {
        return (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $value);
    }
}

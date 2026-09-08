<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * A throwaway copy of a fixture corpus.
 *
 * Anything testing how the index reacts to change has to change something, and the corpus in
 * `tests/Fixtures` is shared, version-controlled data that must not move under another test's
 * feet. A copy also happens to be the honest way to exercise the content-addressed cache: two
 * directories holding the same bytes is precisely the worktree case the design exists for.
 */
final readonly class TemporaryProject
{
    private function __construct(public string $root) {}

    public static function copyOfCorpus(): self
    {
        $root = sys_get_temp_dir().'/exocomp-project-'.bin2hex(random_bytes(6));
        self::copy(Cli::corpusRoot(), $root);

        return new self($root);
    }

    public function path(string $relative): string
    {
        return $this->root.'/'.ltrim($relative, '/');
    }

    public function write(string $relative, string $contents): void
    {
        $path = $this->path($relative);
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0o777, true) && ! is_dir($directory)) {
            throw new RuntimeException("could not create {$directory}");
        }

        file_put_contents($path, $contents);

        // Filesystem timestamps have one-second granularity on some platforms, and the index
        // compares them. Without this, a file rewritten within the same second as its last
        // indexing looks unchanged — which would make the test pass for the wrong reason.
        touch($path, time() + 1);
        clearstatcache(true, $path);
    }

    public function delete(string $relative): void
    {
        @unlink($this->path($relative));
    }

    public function destroy(): void
    {
        if (is_dir($this->root)) {
            $this->remove($this->root);
        }
    }

    private static function copy(string $from, string $to): void
    {
        if (! is_dir($to) && ! mkdir($to, 0o777, true) && ! is_dir($to)) {
            throw new RuntimeException("could not create {$to}");
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            if (! $item instanceof SplFileInfo) {
                continue;
            }

            $target = $to.'/'.substr($item->getPathname(), strlen($from) + 1);

            if ($item->isDir()) {
                if (! is_dir($target)) {
                    mkdir($target, 0o777, true);
                }

                continue;
            }

            copy($item->getPathname(), $target);
        }
    }

    private function remove(string $directory): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item instanceof SplFileInfo) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
        }

        @rmdir($directory);
    }
}

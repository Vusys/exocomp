<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Project;

/**
 * The checkout being analysed: where its root is, and how to name a file inside it.
 *
 * Paths are reported relative to the project root, because that is what makes `file:line:col`
 * a link the caller can act on and what keeps output stable across the disposable worktrees
 * this tool is expected to run in — an absolute path containing a random worktree directory
 * is noise in every line of every answer.
 */
final readonly class Project
{
    private function __construct(public string $root) {}

    /**
     * Walks up from a starting directory to the nearest `composer.json`. Falls back to the
     * starting directory, so the tool still works in a directory that is not a Composer
     * project — degrade, never block.
     */
    public static function discover(string $startingDirectory): self
    {
        $directory = realpath($startingDirectory);

        if ($directory === false) {
            return new self($startingDirectory);
        }

        $candidate = $directory;

        while (true) {
            if (is_file($candidate.'/composer.json')) {
                return new self($candidate);
            }

            $parent = dirname($candidate);

            if ($parent === $candidate) {
                return new self($directory);
            }

            $candidate = $parent;
        }
    }

    public static function at(string $root): self
    {
        // Resolved to an absolute path so that display paths are computed against the same
        // spelling the locator produces — a relative --project would otherwise make every
        // reported path absolute, which is noise in every line of every answer.
        $resolved = realpath($root);

        return new self($resolved === false ? rtrim($root, '/') : $resolved);
    }

    public function displayPath(string $absolutePath): string
    {
        $prefix = $this->root.'/';

        if (str_starts_with($absolutePath, $prefix)) {
            return substr($absolutePath, strlen($prefix));
        }

        return $absolutePath;
    }

    public function path(string $relative): string
    {
        if (str_starts_with($relative, $this->root.'/')) {
            return $relative;
        }

        return $this->root.'/'.ltrim($relative, '/');
    }

    /**
     * `$path` with `.` and `..` worked out by its text alone, if that leaves it inside the
     * project. Unlike `realpath()`, this does not follow a symlink out of the project, so a
     * package Composer linked into `vendor/` keeps its `vendor/` path.
     */
    public function withinRoot(string $path): ?string
    {
        if (! str_starts_with($path, '/')) {
            $path = $this->root.'/'.$path;
        }

        $parts = [];

        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                array_pop($parts);

                continue;
            }

            $parts[] = $part;
        }

        $normal = '/'.implode('/', $parts);

        return str_starts_with($normal, $this->root.'/') ? $normal : null;
    }
}

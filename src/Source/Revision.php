<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Source;

use RuntimeException;

/**
 * A git revision as a read-only filesystem.
 *
 * 193 of the measured commands piped `git show SHA:file` into grep, and no existing PHP
 * intelligence tool can answer a semantic question about a past revision at all — they all read
 * the working tree and nothing else. It is nearly free to support here, because the parser
 * already takes a string rather than a path; all that is needed is somewhere else for the
 * string to come from.
 *
 * The file listing is fetched once and reused, so resolving a name at a revision costs one
 * `ls-tree` regardless of how many candidates are tried.
 */
final class Revision
{
    /** @var array<string, true>|null */
    private ?array $files = null;

    private ?string $resolved = null;

    public function __construct(
        private readonly string $root,
        public readonly string $reference,
    ) {}

    /**
     * The full object name, which is also the check that the reference exists at all.
     */
    public function resolve(): string
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $output = $this->git(['rev-parse', '--verify', $this->reference.'^{commit}']);

        if ($output === null) {
            throw new RuntimeException(sprintf('"%s" is not a revision in this repository', $this->reference));
        }

        return $this->resolved = trim($output);
    }

    public function has(string $path): bool
    {
        return isset($this->files()[$path]);
    }

    /**
     * @return array<string, true>
     */
    public function files(): array
    {
        if ($this->files !== null) {
            return $this->files;
        }

        $this->resolve();
        $output = $this->git(['ls-tree', '-r', '--name-only', '-z', $this->reference]);
        $files = [];

        foreach (explode("\0", $output ?? '') as $path) {
            if ($path !== '') {
                $files[$path] = true;
            }
        }

        return $this->files = $files;
    }

    public function read(string $path): ?string
    {
        return $this->git(['show', $this->reference.':'.$path]);
    }

    public function source(string $path): SourceFile
    {
        $contents = $this->read($path);

        if ($contents === null) {
            throw new RuntimeException(sprintf('"%s" does not exist at %s', $path, $this->reference));
        }

        return SourceFile::fromString($contents, $path);
    }

    /**
     * @param  list<string>  $arguments
     */
    private function git(array $arguments): ?string
    {
        $command = 'git -C '.escapeshellarg($this->root);

        foreach ($arguments as $argument) {
            $command .= ' '.escapeshellarg($argument);
        }

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open($command, $descriptors, $pipes);

        if (! is_resource($process)) {
            return null;
        }

        $output = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);

        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        return proc_close($process) === 0 && is_string($output) ? $output : null;
    }
}

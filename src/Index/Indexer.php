<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Index;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Vusys\Exocomp\Analysis\Analyser;
use Vusys\Exocomp\Project\Project;
use Vusys\Exocomp\Source\ParseFailure;
use Vusys\Exocomp\Source\SourceFile;

/**
 * Brings the index into agreement with what is on disk.
 *
 * Two cheap checks stand between a file and a re-parse. If its size and mtime are unchanged
 * since it was recorded, it is not even hashed. If it hashes to a blob that has already been
 * extracted — by an earlier run here, or by another worktree sharing the same commit — the
 * facts are reused and the parser never runs.
 *
 * A file that cannot be parsed is recorded as a failure and skipped. It must not abort the run:
 * an agent indexes a tree it is halfway through editing, and one broken file is the normal case
 * rather than an exceptional one.
 */
final readonly class Indexer
{
    public function __construct(
        private IndexStore $store,
        private Analyser $analyser = new Analyser,
    ) {}

    /**
     * @param  list<string>  $roots  absolute directories to walk
     */
    public function sync(Project $project, array $roots): IndexReport
    {
        $start = microtime(true);
        $known = $this->store->filesIn($project->root);
        $knownFailures = $this->store->failuresIn($project->root);

        $seen = 0;
        $parsed = 0;
        $reused = 0;
        $carriedFailures = 0;
        $failures = [];
        $present = [];

        $this->store->beginBatch();

        foreach ($roots as $root) {
            foreach ($this->phpFilesIn($root) as $file) {
                $path = $project->displayPath($file->getPathname());
                $present[$path] = true;
                $seen++;

                $size = $file->getSize();
                $mtime = $file->getMTime();
                $record = $known[$path] ?? null;

                if ($record !== null && $record['size'] === $size && $record['mtime'] === $mtime) {
                    $reused++;

                    continue;
                }

                $failed = $knownFailures[$path] ?? null;

                if ($failed !== null && $failed['size'] === $size && $failed['mtime'] === $mtime) {
                    // Unchanged since it last failed, so the outcome cannot have changed either.
                    $carriedFailures++;

                    continue;
                }

                $hash = hash_file('xxh128', $file->getPathname());

                if ($hash === false) {
                    $failures[] = $path.'  could not be read';

                    continue;
                }

                if ($this->store->hasBlob($hash)) {
                    // Same bytes, different path or different worktree. Nothing to parse.
                    $this->store->putFile($project->root, $path, $hash, $mtime, $size);
                    $reused++;

                    continue;
                }

                try {
                    $facts = FileFacts::from($this->analyser->analyse(SourceFile::fromPath($file->getPathname(), $path)));
                } catch (ParseFailure $failure) {
                    $failures[] = sprintf('%s:%d  %s', $failure->path, $failure->startLine, $failure->getMessage());
                    $this->store->putFailure($project->root, $path, $mtime, $size, $failure->startLine, $failure->getMessage());

                    continue;
                }

                $this->store->forgetFailure($project->root, $path);
                $this->store->putBlob($hash, $facts);
                $this->store->putFile($project->root, $path, $hash, $mtime, $size);
                $parsed++;
            }
        }

        $removed = 0;

        foreach (array_keys($known) as $path) {
            if (! isset($present[$path])) {
                $this->store->forgetFile($project->root, $path);
                $removed++;
            }
        }

        foreach (array_keys($knownFailures) as $path) {
            if (! isset($present[$path])) {
                $this->store->forgetFailure($project->root, $path);
            }
        }

        $this->store->commitBatch();

        return new IndexReport($seen, $parsed, $reused, $removed, $failures, $carriedFailures + count($failures), microtime(true) - $start);
    }

    /**
     * @return iterable<SplFileInfo>
     */
    private function phpFilesIn(string $root): iterable
    {
        if (! is_dir($root)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                yield $file;
            }
        }
    }
}

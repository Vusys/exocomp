<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Index;

use Vusys\Exocomp\Analysis\Analyser;
use Vusys\Exocomp\Project\Project;
use Vusys\Exocomp\Source\ParseFailure;
use Vusys\Exocomp\Source\SourceFile;

/**
 * Keeps answers honest without re-checking the world.
 *
 * A stale index is worse than no index, because it is confidently wrong — so something has to
 * be validated on every query. The cheap way to read that requirement is "walk the tree first",
 * and it is the wrong way: on a 6,500-file project that walk measured 83 ms, which is most of
 * the latency budget spent proving that files nobody asked about have not moved.
 *
 * So validation is scoped to the answer. Whatever files a result actually came from are stat-ed
 * and re-indexed if they have changed, and the query is re-run — cost proportional to the
 * answer, not to the corpus. The one thing that cannot catch is a symbol added since the last
 * full pass, which would show up as *nothing found*; so a query that finds nothing triggers one
 * full synchronisation and tries again. A wrong answer is impossible either way; only the
 * pessimistic case pays for a walk.
 */
final class Freshness
{
    private bool $fullySynchronised = false;

    /** @var list<string> */
    private array $notes = [];

    public function __construct(
        private readonly IndexStore $store,
        private readonly Project $project,
        private readonly Indexer $indexer,
        /** @var list<string> */
        private readonly array $roots,
    ) {}

    /**
     * Re-indexes any of these files whose bytes have moved on since they were recorded.
     *
     * @param  list<string>  $paths  project-relative
     * @return bool whether anything changed, meaning the caller should ask again
     */
    public function refresh(array $paths): bool
    {
        if ($paths === []) {
            return false;
        }

        $known = $this->store->stampsFor($this->project->root, $paths);
        $changed = false;

        foreach ($paths as $path) {
            $absolute = $this->project->path($path);
            $stamp = $known[$path] ?? null;

            if (! is_file($absolute)) {
                $this->store->forgetFile($this->project->root, $path);
                $this->notes[] = $path.' has been deleted since it was indexed';
                $changed = true;

                continue;
            }

            $size = filesize($absolute);
            $mtime = filemtime($absolute);

            if ($stamp !== null && $stamp['size'] === $size && $stamp['mtime'] === $mtime) {
                continue;
            }

            $this->reindex($path, $absolute, $mtime === false ? 0 : $mtime, $size === false ? 0 : $size);
            $changed = true;
        }

        return $changed;
    }

    /**
     * One full pass, at most once per process.
     */
    public function full(): bool
    {
        if ($this->fullySynchronised) {
            return false;
        }

        $this->fullySynchronised = true;
        $report = $this->indexer->sync($this->project, $this->roots);

        if ($report->changedAnything()) {
            $this->notes[] = sprintf(
                'index updated: %d parsed, %d reused, %d removed in %.2fs',
                $report->parsed,
                $report->reused,
                $report->removed,
                $report->seconds,
            );
        }

        if ($report->knownFailures > 0) {
            $this->notes[] = sprintf(
                '%d file%s could not be parsed and %s not in the index (see: exocomp index --status)',
                $report->knownFailures,
                $report->knownFailures === 1 ? '' : 's',
                $report->knownFailures === 1 ? 'is' : 'are',
            );
        }

        return $report->changedAnything();
    }

    /**
     * @return list<string>
     */
    public function notes(): array
    {
        return $this->notes;
    }

    private function reindex(string $path, string $absolute, int $mtime, int $size): void
    {
        $hash = hash_file('xxh128', $absolute);

        if ($hash === false) {
            return;
        }

        $this->store->beginBatch();

        try {
            if (! $this->store->hasBlob($hash)) {
                $facts = FileFacts::from((new Analyser)->analyse(SourceFile::fromPath($absolute, $path)));
                $this->store->putBlob($hash, $facts);
            }

            $this->store->putFile($this->project->root, $path, $hash, $mtime, $size);
            $this->store->forgetFailure($this->project->root, $path);
            $this->notes[] = $path.' had changed and was re-read';
        } catch (ParseFailure $failure) {
            $this->store->forgetFile($this->project->root, $path);
            $this->store->putFailure($this->project->root, $path, $mtime, $size, $failure->startLine, $failure->getMessage());
            $this->notes[] = sprintf('%s:%d no longer parses, so it was dropped from the index', $path, $failure->startLine);
        } finally {
            $this->store->commitBatch();
        }
    }
}

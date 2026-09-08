<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Index;

/**
 * What one synchronisation actually did.
 *
 * `reused` is the number that matters: files whose contents were already extracted, either
 * because they have not changed here or because another checkout indexed the same bytes first.
 */
final readonly class IndexReport
{
    /**
     * @param  list<string>  $failures  files that failed to parse *on this run*; ones that
     *                                  failed earlier and have not changed are counted in
     *                                  `knownFailures` rather than announced again
     */
    public function __construct(
        public int $seen,
        public int $parsed,
        public int $reused,
        public int $removed,
        public array $failures,
        public int $knownFailures,
        public float $seconds,
    ) {}

    public function changedAnything(): bool
    {
        return $this->parsed > 0 || $this->removed > 0;
    }
}

<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Output;

/**
 * Which of an answer's records one page holds, and the command that prints the next: "records
 * 1–50 of 312; next: exocomp callers-of … --page 2".
 */
final readonly class PageWindow
{
    public function __construct(
        public int $first,
        public int $last,
        public int $total,
        public ?string $next,
    ) {}

    public function describe(): string
    {
        return sprintf('records %d–%d of %d', $this->first, $this->last, $this->total)
            .($this->next === null ? '; the last page' : sprintf('; next: %s', $this->next));
    }
}

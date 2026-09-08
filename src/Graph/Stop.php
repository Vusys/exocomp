<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Graph;

/**
 * A place the walk could not continue, and why.
 *
 * This list is the reason a negative answer from `reaches` is worth anything. "No path found"
 * is a claim about the whole graph, and a graph assembled from a language with dynamic
 * dispatch is never complete — so an unqualified "no" is exactly the confidently-wrong answer
 * the design rules out. Reported alongside the verdict, these say how much of the frontier was
 * actually followed, and a reader can see at a glance whether the gap is somewhere that could
 * plausibly lead to what they asked about.
 */
final readonly class Stop
{
    public const string UNRESOLVED = 'unresolved';

    public const string DEPENDENCY = 'dependency';

    public const string UNREADABLE = 'unreadable';

    public function __construct(
        public string $from,
        public string $kind,
        public string $path,
        public int $line,
        public int $column,
        public string $reason,
        public string $detail,
    ) {}

    public function key(): string
    {
        return $this->from.'|'.$this->path.':'.$this->line.'|'.$this->detail;
    }
}

<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Graph;

use Vusys\Exocomp\Symbol\Confidence;

/**
 * One step from one declaration to another, and what said it was there.
 *
 * A path is only as trustworthy as its weakest edge, so every edge carries its own confidence
 * rather than the path carrying one for the whole walk. A route that reaches a write through
 * four resolved calls and one container binding is a different claim from one that reaches it
 * through five resolved calls, and collapsing the two would hide exactly the step a reader
 * needs to check.
 */
final readonly class Edge
{
    public function __construct(
        public string $from,
        public string $to,
        public string $kind,
        public string $path,
        public int $line,
        public int $column,
        public Confidence $confidence,
        public string $reason,
    ) {}

    /**
     * The weakest link in a walk — what the whole path is actually worth.
     *
     * @param  list<self>  $edges
     */
    public static function weakest(array $edges): Confidence
    {
        return Confidence::weakest(...array_map(static fn (self $edge): Confidence => $edge->confidence, $edges));
    }
}

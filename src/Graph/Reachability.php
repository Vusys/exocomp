<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Graph;

use Vusys\Exocomp\Symbol\Confidence;

/**
 * What one walk found, and what it did not look at.
 *
 * The three facts a reader needs about a negative result are all here and none of them is
 * optional: how far the walk got, whether a budget cut it short, and where it stopped. A
 * "no" that came back after expanding four nodes and hitting the depth limit is a different
 * statement from a "no" that exhausted every reachable declaration, and a verdict that did not
 * distinguish them would be untrustworthy in both directions.
 */
final readonly class Reachability
{
    /**
     * @param  list<Edge>  $path
     * @param  list<Stop>  $stops
     */
    public function __construct(
        public string $start,
        public bool $found,
        public array $path,
        public int $expanded,
        public bool $exhausted,
        public array $stops,
    ) {}

    public function confidence(): Confidence
    {
        return Edge::weakest($this->path);
    }

    /**
     * @return list<Stop>
     */
    public function stopsOfKind(string $kind): array
    {
        return array_values(array_filter($this->stops, static fn (Stop $stop): bool => $stop->kind === $kind));
    }
}

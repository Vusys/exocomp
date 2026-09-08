<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Index;

/**
 * A parent, interface or trait the index could not reach.
 *
 * Reporting these is what keeps a member list honest. A class extending an un-indexed framework
 * base has an API far larger than its own declarations, and returning the short list without
 * comment would read as the whole thing — which is the confidently-wrong failure applied to
 * absence rather than to content.
 */
final readonly class UnreachableAncestor
{
    public function __construct(
        public string $fqn,
        public string $relation,
        public string $reason,
    ) {}
}

<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Graph;

/**
 * Somewhere a walk begins, and what to call it in the answer.
 *
 * A start is not always a symbol. The question that motivated this whole layer — does any
 * endpoint reach this write — is asked about routes, and a route's own address is the line in
 * the route file, not the controller method it happens to point at. Keeping the label and the
 * node separate is what lets the answer name the endpoint the reader asked about while walking
 * from the code that answers it.
 */
final readonly class Start
{
    /**
     * @param  list<Edge>  $before  what runs before the node and is walked as though it called
     *                              it: a route's middleware
     */
    public function __construct(
        public string $node,
        public string $label,
        public string $path,
        public int $line,
        public array $before = [],
    ) {}
}

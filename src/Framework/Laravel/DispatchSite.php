<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Framework\Laravel;

use Vusys\Exocomp\Symbol\Confidence;

/**
 * One place a job or an event is sent, and the class it sends.
 */
final readonly class DispatchSite
{
    /**
     * @param  int  $position  the byte offset the sending expression starts at, so the edge can
     *                         be matched back to the exact call rather than to its line — two
     *                         calls on one line are two different facts
     */
    public function __construct(
        public string $class,
        public string $sender,
        public int $line,
        public int $position,
        public Confidence $confidence = Confidence::Resolved,
        public ?string $note = null,
    ) {}
}

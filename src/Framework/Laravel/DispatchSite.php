<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Framework\Laravel;

use Vusys\Exocomp\Symbol\Confidence;

/**
 * One place a job or an event is sent, and the class it sends.
 */
final readonly class DispatchSite
{
    public function __construct(
        public string $class,
        public string $sender,
        public int $line,
        public Confidence $confidence = Confidence::Resolved,
        public ?string $note = null,
    ) {}
}

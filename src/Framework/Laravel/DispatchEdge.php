<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Framework\Laravel;

use Vusys\Exocomp\Symbol\Confidence;

/**
 * One end of a dispatch: a place something is sent, or a place it is received.
 */
final readonly class DispatchEdge
{
    public function __construct(
        public string $kind,
        public string $subject,
        public ?string $counterpart,
        public string $path,
        public int $line,
        public Confidence $confidence,
        public ?string $note = null,
    ) {}
}

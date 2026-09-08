<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Inference;

use Vusys\Exocomp\Symbol\Confidence;

/**
 * One call made from inside a declaration, with whatever could be established about where it
 * goes.
 */
final readonly class OutgoingCall
{
    public function __construct(
        public string $path,
        public int $line,
        public int $column,
        public string $kind,
        public ?string $target,
        public Confidence $confidence,
        public string $reason,
        public string $snippet,
    ) {}
}

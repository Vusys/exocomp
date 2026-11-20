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
        /** the class the call was made on, where that differs from where the member is declared */
        public ?string $receiver = null,
        /** the authorization check this call makes, where it is one */
        public ?GateCheck $gate = null,
    ) {}
}

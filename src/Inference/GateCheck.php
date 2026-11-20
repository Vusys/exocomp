<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Inference;

use Vusys\Exocomp\Symbol\Confidence;

/**
 * An authorization check written at a call site: `$this->authorize('update', $order)`,
 * `Gate::allows('update', $order)`, `$user->can('update', $order)`. The ability and the class of
 * what it is checked against are what pick the policy method that runs.
 */
final readonly class GateCheck
{
    public function __construct(
        /** null where the ability or the subject could not be read, so no policy can be named */
        public ?string $ability,
        public ?string $subject,
        public Confidence $confidence,
        public string $reason,
    ) {}
}

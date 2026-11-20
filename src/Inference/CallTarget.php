<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Inference;

use Vusys\Exocomp\Symbol\Confidence;

/**
 * What a call site actually calls.
 *
 * Knowing the receiver's type is only half of it. The tool used to stop there and report
 * `Receiver::whateverWasWritten`, which is right for code that compiles and confidently wrong
 * for everything else: a member that is not declared anywhere, on a class read to the end of its
 * ancestry, is not a call target — it is either a broken call or a magic one, and both deserve
 * to be said out loud rather than papered over with the name the caller happened to type.
 */
final readonly class CallTarget
{
    private function __construct(
        public ?string $fqn,
        public Confidence $confidence,
        public string $reason,
        public ?string $lands = null,
    ) {}

    /**
     * @param  ?string  $lands  the declaration that runs, when it is not `$fqn` itself: the
     *                          address is the member as reached through the receiver,
     *                          `Order::find`, and this is where it lands, `Builder::find`
     */
    public static function at(string $fqn, Confidence $confidence, string $reason, ?string $lands = null): self
    {
        return new self($fqn, $confidence, $reason, $lands !== null && strcasecmp($lands, $fqn) !== 0 ? $lands : null);
    }

    public static function none(string $reason): self
    {
        return new self(null, Confidence::Textual, $reason);
    }
}

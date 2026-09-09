<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Inference;

use Vusys\Exocomp\Symbol\Confidence;

/**
 * A member that exists at runtime without being declared where it is used.
 *
 * Two quite different things arrive in this shape, and keeping them apart is the point of the
 * class. A *convention* names a real declaration somewhere — `Order::active()` is
 * `Order::scopeActive()`, spelled the way the framework spells it — so there is somewhere to
 * jump to and something a rename must keep in step. A *claim* names no declaration at all: an
 * annotation says the member is there and nothing in the source backs it up.
 *
 * The first can be worth {@see Confidence::Resolved}, because the transform is a total function
 * of the name and the target was found and checked. The second never can be, for the reason the
 * docblock inference already gives: an annotation is a claim about the code, not a fact of it.
 */
final readonly class MagicMember
{
    private function __construct(
        public ?string $declaration,
        public ?string $type,
        public Confidence $confidence,
        public string $reason,
    ) {}

    /**
     * The member is really declared, under a name the framework transforms.
     */
    public static function spelled(string $declaration, ?string $type, Confidence $confidence, string $reason): self
    {
        return new self($declaration, $type, $confidence, $reason);
    }

    /**
     * The class half of the declaration this resolves to, if it resolves to one.
     */
    public function owner(): ?string
    {
        return $this->declaration === null ? null : explode('::', $this->declaration)[0];
    }

    /**
     * The member half.
     */
    public function member(): ?string
    {
        return $this->declaration === null ? null : (explode('::', $this->declaration)[1] ?? null);
    }

    /**
     * Something says the member is there. Nothing declares it.
     */
    public static function claimed(?string $type, string $reason): self
    {
        return new self(null, $type, Confidence::Inferred, $reason);
    }
}

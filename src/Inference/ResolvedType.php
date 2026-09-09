<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Inference;

use Vusys\Exocomp\Symbol\Confidence;

/**
 * A type, and how much the tool is prepared to stand behind it.
 *
 * The confidence is not decoration. It is what makes partial inference shippable: coverage
 * becomes a number that can be reported and improved, instead of a cliff that has to be
 * reached before anything is trustworthy. And on the write side it is the difference between a
 * reference that may be rewritten and one that must be handed back for a human to judge.
 */
final readonly class ResolvedType
{
    private function __construct(
        public ?string $fqn,
        public Confidence $confidence,
        public string $reason,
    ) {}

    /**
     * Established from a declaration: a parameter type, a property type, a return type, `$this`.
     * In modern typed PHP this covers the overwhelming majority of receivers, because the types
     * are simply written down.
     */
    public static function declared(string $fqn): self
    {
        return new self($fqn, Confidence::Resolved, 'declared type');
    }

    public static function resolvedBy(string $fqn, string $reason): self
    {
        return new self($fqn, Confidence::Resolved, $reason);
    }

    /**
     * A type established at a confidence the caller worked out — used where a chain of evidence
     * decides the strength rather than a single kind of declaration.
     */
    public static function of(string $fqn, Confidence $confidence, string $reason): self
    {
        return new self($fqn, $confidence, $reason);
    }

    /**
     * Established from something that was written down but is not binding — a docblock, most
     * often. Worth reporting, never worth rewriting on.
     */
    public static function inferred(string $fqn, string $reason): self
    {
        return new self($fqn, Confidence::Inferred, $reason);
    }

    /**
     * Not established at all. This is a first-class answer rather than a failure: the tests
     * that matter most assert that the tool reaches it, because a receiver it cannot know and
     * claims to know is worse than one it admits to.
     */
    public static function unknown(string $reason): self
    {
        return new self(null, Confidence::Textual, $reason);
    }

    public function isKnown(): bool
    {
        return $this->fqn !== null;
    }
}

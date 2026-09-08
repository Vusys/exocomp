<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Symbol;

/**
 * How much the tool actually knows about a result.
 *
 * This is the single most important trust property in the design: a semantic tool that is
 * *confidently wrong* is worse than grep, because grep is honestly uncertain and the reader
 * compensates. Everything the tool emits carries one of these, and the write side (later
 * phases) is only ever allowed to touch {@see self::Resolved}.
 */
enum Confidence: string
{
    /** Established from a declaration or an unambiguous declared type. */
    case Resolved = 'resolved';

    /** Established from a docblock, a container binding, or another heuristic. */
    case Inferred = 'inferred';

    /** Name match only — no type information stands behind it. */
    case Textual = 'textual';

    /**
     * How much a chain of steps is worth: the least of them.
     *
     * A conclusion reached through four certain steps and one guess is a guess, and reporting
     * it at the strength of its strongest link is the confidently-wrong failure this whole
     * enum exists to prevent.
     */
    public static function weakest(self ...$of): self
    {
        $weakest = self::Resolved;

        foreach ($of as $one) {
            if ($one === self::Textual) {
                return self::Textual;
            }

            if ($one === self::Inferred) {
                $weakest = self::Inferred;
            }
        }

        return $weakest;
    }
}

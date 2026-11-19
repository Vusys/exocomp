<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Invoice;
use RuntimeException;

/**
 * Receivers whose only type is an instanceof check. Each method name says whether the call is
 * an Invoice by the time it runs.
 */
final class NarrowedCallers
{
    public function insideTheBlock($thing): void
    {
        if ($thing instanceof Invoice) {
            $thing->send();
        }
    }

    public function inTheRestOfTheCondition($thing): bool
    {
        return $thing instanceof Invoice && $thing->send() === null;
    }

    public function inTheFirstBranchOfATernary($thing): void
    {
        $thing instanceof Invoice ? $thing->send() : null;
    }

    public function notBeforeTheTest($thing): bool
    {
        return $thing->send() === null && $thing instanceof Invoice;
    }

    public function afterAGuardThatThrows($thing): void
    {
        if (! $thing instanceof Invoice) {
            throw new RuntimeException('not an invoice');
        }

        $thing->send();
    }

    public function afterAGuardThatReturns($thing, $other): void
    {
        if (! $thing instanceof Invoice || ! $other instanceof Invoice) {
            return;
        }

        $other->send();
    }

    public function afterAnAssertion($thing): void
    {
        assert($thing instanceof Invoice);

        $thing->send();
    }

    public function notInTheElse($thing): void
    {
        if ($thing instanceof Invoice) {
            return;
        } else {
            $thing->send();
        }
    }

    public function notAfterTheBlock($thing): void
    {
        if ($thing instanceof Invoice) {
            $thing->id;
        }

        $thing->send();
    }

    public function notAfterAGuardThatFallsThrough($thing): void
    {
        if (! $thing instanceof Invoice) {
            report('not an invoice');
        }

        $thing->send();
    }

    public function notAfterAnEitherOrGuard($thing): void
    {
        foreach ([1] as $_) {
            if (! $thing instanceof Invoice && $_ === 1) {
                continue;
            }

            $thing->send();
        }
    }
}

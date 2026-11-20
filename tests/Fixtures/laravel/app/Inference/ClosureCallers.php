<?php

declare(strict_types=1);

namespace App\Inference;

/**
 * Two methods, each calling Gateway::refund only from inside a closure: two callers, not one.
 */
final class ClosureCallers
{
    public function first(): void
    {
        array_map(fn (Gateway $g) => $g->refund(1), []);
    }

    public function second(): void
    {
        array_map(function (Gateway $g) {
            return $g->refund(2);
        }, []);
    }
}

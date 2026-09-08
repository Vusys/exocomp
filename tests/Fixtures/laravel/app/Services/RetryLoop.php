<?php

declare(strict_types=1);

namespace App\Services;

/**
 * A cycle. Two methods that call each other is the shape a bounded walk has to survive without
 * a depth limit doing the work — the limit is there to bound cost, not to be the only thing
 * standing between the tool and an infinite loop.
 */
final class RetryLoop
{
    public function attempt(): void
    {
        $this->retry();
    }

    public function retry(): void
    {
        $this->attempt();
    }
}

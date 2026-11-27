<?php

declare(strict_types=1);

namespace App\Inference;

/**
 * The class the probes in this directory reach, so each probe's answer is one method on it.
 */
class Gateway
{
    public function refund(int $amount): bool
    {
        return $amount > 0;
    }
}

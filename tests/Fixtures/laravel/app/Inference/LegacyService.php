<?php

declare(strict_types=1);

namespace App\Inference;

/**
 * Assigns its dependency to a property it never declares, as older code does.
 */
final class LegacyService
{
    public function __construct(Gateway $gateway)
    {
        $this->gateway = $gateway;
    }

    public function refund(): void
    {
        $this->gateway->refund(1);
    }
}

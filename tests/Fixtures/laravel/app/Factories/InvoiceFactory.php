<?php

declare(strict_types=1);

namespace App\Factories;

/**
 * A model's own factory, with a state method written the usual way: returning `static`.
 */
class InvoiceFactory
{
    public function paid(): static
    {
        return $this;
    }
}

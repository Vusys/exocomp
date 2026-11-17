<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Invoice;

/**
 * A fluent class of the application's own. `static` and `self` name a class only relative to
 * the call, so a chain through them must follow the receiver, not stop at a class called "static".
 */
final class ReportQuery
{
    public function since(string $date): static
    {
        return $this;
    }

    public function latest(): self
    {
        return $this;
    }

    public function invoice(): Invoice
    {
        return new Invoice;
    }
}

<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Invoice;

/**
 * Registered nowhere: Laravel finds it by name, App\Models\Invoice to App\Policies\InvoicePolicy.
 */
final class InvoicePolicy
{
    public function view($user, Invoice $invoice): bool
    {
        return true;
    }
}

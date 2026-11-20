<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Invoice;
use App\Services\AuditLog;

/**
 * Registered in EventServiceProvider's $observers.
 */
final class InvoiceObserver
{
    public function deleting(Invoice $invoice): void
    {
        AuditLog::record('invoice deleting');
    }
}

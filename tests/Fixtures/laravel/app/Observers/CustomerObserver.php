<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Customer;
use App\Services\AuditLog;

/**
 * Registered by #[ObservedBy] on Customer.
 */
final class CustomerObserver
{
    public function updated(Customer $customer): void
    {
        AuditLog::record('customer updated');
    }
}

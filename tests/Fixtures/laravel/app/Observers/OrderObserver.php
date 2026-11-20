<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Order;
use App\Services\AuditLog;

/**
 * Registered with Order::observe() in AppServiceProvider::boot().
 */
final class OrderObserver
{
    public function created(Order $order): void
    {
        AuditLog::record('order created');
    }

    public function deleted(Order $order): void
    {
        AuditLog::record('order deleted');
    }
}

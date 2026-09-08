<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\OrderPlaced;

/**
 * Registered nowhere. The framework discovers it by the type of its handle() parameter, which
 * is a convention applied at boot rather than anything the source declares — so it is inferred.
 */
final class RecordMetrics
{
    public function handle(OrderPlaced $event): void
    {
        $event->order->touch();
    }
}

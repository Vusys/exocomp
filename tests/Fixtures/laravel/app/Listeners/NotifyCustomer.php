<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\OrderPlaced;

/**
 * Registered in the provider's $listen array — a declaration, read literally.
 */
final class NotifyCustomer
{
    public function handle(OrderPlaced $event): void
    {
        $event->order->save();
    }
}

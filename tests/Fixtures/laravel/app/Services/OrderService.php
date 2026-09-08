<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\OrderPlaced;
use App\Jobs\ProcessOrder;
use App\Models\Order;

final class OrderService
{
    public function place(Order $order): void
    {
        dispatch(new ProcessOrder($order));

        ProcessOrder::dispatch($order);

        event(new OrderPlaced($order));
    }
}

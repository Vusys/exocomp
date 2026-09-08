<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Order;

/**
 * A queued job. `dispatch(new ProcessOrder($order))` continues execution here, in another
 * process, and nothing at the call site names this class.
 */
final class ProcessOrder
{
    public function __construct(private readonly Order $order) {}

    public function handle(): void
    {
        $this->order->save();
    }
}

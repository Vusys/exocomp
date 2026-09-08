<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Order;

final class OrderPlaced
{
    public function __construct(public readonly Order $order) {}
}

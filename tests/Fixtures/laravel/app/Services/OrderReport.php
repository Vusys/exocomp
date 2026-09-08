<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Order;

/**
 * Eloquent's `__get` magic: `$order->customer` is the relation `customer()`, loaded and
 * returned as a model. Plain PHP says this is a property access on a class that declares no
 * such property, and stops there — so a rename of the relation method that ignores these is a
 * rename that breaks at runtime.
 */
final class OrderReport
{
    public function via(Order $order): string
    {
        // The relation, called as a method.
        $query = $order->customer();

        // The same relation, read as an attribute.
        $name = $order->customer;

        return $query->toSql().(string) $name;
    }
}

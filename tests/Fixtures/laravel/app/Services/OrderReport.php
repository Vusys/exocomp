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

    /**
     * Neither the model nor anything readable declares `whereTenant()`. Eloquent forwards it to
     * a query builder through a magic `__call` on a base class in `vendor/`, so the honest
     * answer is "I cannot see that far" — and not "this method does not exist", which reads as
     * a claim that the call is broken.
     */
    public function scopedCount(Order $order): int
    {
        return $order->whereTenant(1)->count();
    }
}

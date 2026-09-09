<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Order;

/**
 * Call sites for the members Eloquent lets you spell differently.
 *
 * Not one of these writes the name of the declaration it reaches. That is the whole difficulty:
 * a rename driven by a search for `scopeActive` finds this file zero times and reports full
 * confidence in the answer, which in PHP breaks at runtime on whichever path happens to run.
 */
final class OrderQueries
{
    /** A local scope, called statically the way the framework intends. */
    public function active(): mixed
    {
        return Order::active();
    }

    /** The same scope on an instance. */
    public function activeFor(Order $order): mixed
    {
        return $order->active();
    }

    /** An accessor, read as an attribute. */
    public function label(Order $order): string
    {
        return $order->display_name;
    }

    /** The newer accessor spelling, read the same way. */
    public function code(Order $order): mixed
    {
        return $order->short_code;
    }

    /** A relation read as an attribute rather than called as a method. */
    public function customerOf(Order $order): mixed
    {
        return $order->customer;
    }

    /**
     * A column. Nothing declares it anywhere — it is in the migrations, and the tool must not
     * invent a declaration for it.
     */
    public function placedAt(Order $order): mixed
    {
        return $order->placed_at;
    }

    /** A macro. Nothing declares it; a provider registers it under a literal name. */
    public function summary(\App\Services\ReportBuilder $builder): mixed
    {
        return $builder->summarise();
    }

    /** Neither of these is magic: the receiver is not a model. */
    public function notAModel(NotAModel $plain): mixed
    {
        return $plain->active();
    }

    public function notAnAttribute(NotAModel $plain): mixed
    {
        return $plain->display_name;
    }
}

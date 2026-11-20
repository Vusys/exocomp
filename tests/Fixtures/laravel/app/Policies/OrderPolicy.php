<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Order;

/**
 * Registered in AuthServiceProvider's $policies.
 */
final class OrderPolicy
{
    public function before($user): ?bool
    {
        return null;
    }

    public function update($user, Order $order): bool
    {
        return true;
    }
}

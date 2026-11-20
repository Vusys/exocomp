<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Customer;

/**
 * Registered with Gate::policy() in AppServiceProvider::boot().
 */
final class CustomerPolicy
{
    public function forceDelete($user, Customer $customer): bool
    {
        return false;
    }
}

<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\OrderLine;

/**
 * Registered by #[UsePolicy] on OrderLine. Its name follows no convention, so only the attribute
 * finds it.
 */
final class LineItemRules
{
    public function delete($user, OrderLine $line): bool
    {
        return false;
    }
}

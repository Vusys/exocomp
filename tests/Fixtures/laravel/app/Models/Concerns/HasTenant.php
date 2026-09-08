<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Scopes\TenantScope;

/**
 * A scoping trait — the construct at the centre of the cross-tenant bug family, and the reason
 * `model` has to report scopes that are applied somewhere other than the model's own body.
 */
trait HasTenant
{
    protected static function bootHasTenant(): void
    {
        static::addGlobalScope(new TenantScope);
    }
}

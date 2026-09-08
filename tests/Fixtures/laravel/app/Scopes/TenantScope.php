<?php

declare(strict_types=1);

namespace App\Scopes;

use Illuminate\Database\Eloquent\Scope;

final class TenantScope implements Scope
{
    public function apply($builder, $model): void
    {
        $builder->where('tenant_id', 1);
    }
}

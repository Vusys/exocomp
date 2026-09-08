<?php

declare(strict_types=1);

namespace App\Models;

use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * The ordinary shape for anything authenticatable: the parent is a framework class, so the
 * index holds an edge pointing out of the corpus and the inheritance walk stops one hop short
 * of `Model`.
 */
final class Account extends Authenticatable
{
    protected $table = 'accounts';

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);

        // A named closure. What it does is not readable, and saying nothing about it is how a
        // scoping audit comes back clean.
        static::addGlobalScope('ordered', fn (Builder $query) => $query->orderBy('id'));
    }
}

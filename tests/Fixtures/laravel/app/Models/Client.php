<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A model whose relations point at the chain-typing target: one to many, one to one.
 */
final class Client extends Model
{
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class)->latest();
    }

    public function latestInvoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }
}

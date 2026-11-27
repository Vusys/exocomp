<?php

declare(strict_types=1);

namespace App\Inference;

use App\Models\Invoice;
use App\Models\Order;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A model with a relation written without a return type, as older code has, and both kinds of
 * local scope.
 */
class Shipment extends Model
{
    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    #[Scope]
    protected function paid(Builder $query): void
    {
        $query->where('paid', true);
    }

    public function scopeOpen(Builder $query): void
    {
        $query->where('open', true);
    }
}

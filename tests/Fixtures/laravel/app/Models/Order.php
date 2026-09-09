<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasTenant;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

final class Order extends Model
{
    use HasTenant;

    protected $table = 'shop_orders';

    protected $connection = 'shop';

    protected $fillable = ['customer_id', 'reference', 'total_pence'];

    protected $casts = [
        'placed_at' => 'datetime',
        'status' => OrderStatus::class,
    ];

    protected $hidden = ['internal_note'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class)->orderBy('position');
    }

    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    /**
     * Not a relation, and a reader that decided by name alone would say it was.
     */
    public function reference(): string
    {
        return (string) $this->reference;
    }

    /**
     * A local scope. Callers write `Order::active()`; nothing anywhere writes `scopeActive`
     * except this line, which is why finding references to it means searching for the other
     * spelling as well.
     */
    public function scopeActive($query)
    {
        return $query;
    }

    /**
     * The older accessor spelling. Read as `$order->display_name`.
     */
    public function getDisplayNameAttribute(): string
    {
        return (string) $this->reference;
    }

    /**
     * The newer one. Read as `$order->short_code`, and marked as an attribute by its return
     * type rather than by its name — the name alone would match every method on the model.
     */
    protected function shortCode(): Attribute
    {
        return Attribute::make();
    }
}

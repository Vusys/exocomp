<?php

declare(strict_types=1);

namespace App\Models;

use App\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The target of the chain-typing fixture: a model with a method to find calls to, a local scope
 * a builder chain can pass through, and a factory of its own.
 *
 * The `@method` line is what laravel-ide-helper generates. It names the bare framework builder,
 * so Eloquent's own convention has to outrank it for a chain through `query()` to reach the model.
 *
 * @method static \Illuminate\Database\Eloquent\Builder|Invoice query()
 * @method static \App\Factories\InvoiceFactory factory($count = null, $state = [])
 */
final class Invoice extends Model
{
    public function send(): void {}

    /** Shares its name with the request's `user()`, as nearly every model's `user` relation does. */
    public function user(): void {}

    public function scopeOverdue($query)
    {
        return $query;
    }

    protected static function newFactory(): InvoiceFactory
    {
        return new InvoiceFactory;
    }
}

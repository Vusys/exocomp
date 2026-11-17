<?php

declare(strict_types=1);

namespace App\Models;

use App\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The target of the chain-typing fixture: a model with a method to find calls to, a local scope
 * a builder chain can pass through, and a factory of its own.
 */
final class Invoice extends Model
{
    public function send(): void {}

    public function scopeOverdue($query)
    {
        return $query;
    }

    protected static function newFactory(): InvoiceFactory
    {
        return new InvoiceFactory;
    }
}

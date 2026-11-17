<?php

declare(strict_types=1);

namespace App\Models;

use App\Relations\HistoryMany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A model with a relation of a custom class, built by a factory of the package's own, and an
 * override of one of Eloquent's own relation factories, which declares no relation at all.
 */
final class Ledger extends Model
{
    /** Default attribute values: a literal, a literal null, and an expression the reader does not evaluate. */
    protected $attributes = [
        'status' => 'open',
        'closed_at' => null,
        'grace_seconds' => 60 * 60,
    ];

    public function entries(): HistoryMany
    {
        return $this->historyMany(Order::class);
    }

    public function owner(): Customer
    {
        return new Customer;
    }

    protected function newHasMany(Builder $query, Model $parent, $foreignKey, $localKey): HasMany
    {
        return new HasMany;
    }
}

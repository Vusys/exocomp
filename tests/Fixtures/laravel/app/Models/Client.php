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

    /** Built into a variable and returned through a helper, as BookStack writes its ordered relations. */
    public function drafts(): HasMany
    {
        $relation = $this->hasMany(Invoice::class)->latest();

        return $this->ordered($relation);
    }

    private function ordered(HasMany $relation): HasMany
    {
        return $relation;
    }

    public function latestInvoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }
}

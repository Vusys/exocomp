<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A self-referencing relation written once in a trait, as tree packages write them. In a trait,
 * `static::class` is the model the question is about and `self::class` is the class that uses
 * the trait. Neither is the trait.
 */
trait HasTree
{
    public function parent(): BelongsTo
    {
        return $this->belongsTo(static::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(static::class, 'parent_id');
    }

    public function origin(): BelongsTo
    {
        return $this->belongsTo(self::class, 'origin_id');
    }
}

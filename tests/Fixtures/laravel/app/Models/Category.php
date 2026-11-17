<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A model that gets its tree relations from a trait on its parent. Read as Category, the trait's
 * `static::class` is Category, its `self::class` is Node, and `parent::class` here is Node too.
 */
final class Category extends Node
{
    public function template(): BelongsTo
    {
        return $this->belongsTo(parent::class, 'template_id');
    }
}

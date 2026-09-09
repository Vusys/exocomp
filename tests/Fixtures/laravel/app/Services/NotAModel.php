<?php

declare(strict_types=1);

namespace App\Services;

/**
 * A plain class that happens to be spelled like a model.
 *
 * The conventions are the framework's, not the language's, so they apply to Eloquent models and
 * to nothing else. Without that boundary every class with a `scope`-prefixed method or a
 * `get…Attribute()` would acquire members it does not have, which is the guessing this whole
 * layer exists to avoid.
 */
final class NotAModel
{
    public function scopeActive(mixed $query): mixed
    {
        return $query;
    }

    public function getDisplayNameAttribute(): string
    {
        return 'x';
    }
}

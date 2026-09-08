<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

final class Comment extends Model
{
    /**
     * A polymorphic relation names no class at all. What it can point at is decided by the
     * morph map, at runtime — so this is the case the profile must decline to resolve.
     */
    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }
}

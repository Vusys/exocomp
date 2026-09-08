<?php

namespace Illuminate\Database\Eloquent;

/**
 * A stand-in for the framework's base model. Only its name and its position in the hierarchy
 * matter here; nothing reads its body.
 */
abstract class Model
{
    protected $casts = [];

    public function save(): bool
    {
        return true;
    }

    public function touch(): bool
    {
        return true;
    }
}

<?php

namespace Illuminate\Database\Eloquent\Casts;

/**
 * The marker the newer accessor spelling returns. Without the return type the method name alone
 * proves nothing — every ordinary method on a model would match the convention.
 */
class Attribute
{
    public static function make(...$arguments): self
    {
        return new self;
    }
}

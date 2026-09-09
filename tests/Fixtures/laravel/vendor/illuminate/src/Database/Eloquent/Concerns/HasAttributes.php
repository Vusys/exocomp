<?php

namespace Illuminate\Database\Eloquent\Concerns;

/**
 * Where the framework really keeps the accessor convention: on a trait, not on the model. A
 * confirmation that only looked at `Model` and `Builder` would miss this and quietly downgrade
 * every accessor in the corpus while reporting that it had checked.
 */
trait HasAttributes
{
    public function hasGetMutator($key): bool
    {
        return method_exists($this, 'get'.$key.'Attribute');
    }

    public function hasAttributeMutator($key): bool
    {
        return method_exists($this, $key);
    }
}

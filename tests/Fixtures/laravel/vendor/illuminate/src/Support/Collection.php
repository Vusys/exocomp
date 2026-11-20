<?php

namespace Illuminate\Support;

/**
 * @template TKey of array-key
 * @template TValue
 */
class Collection
{
    /**
     * @return static<TKey, TValue>
     */
    public function filter(?callable $callback = null)
    {
        return $this;
    }

    /**
     * @return static<TKey, mixed>
     */
    public function map(callable $callback)
    {
        return $this;
    }
}

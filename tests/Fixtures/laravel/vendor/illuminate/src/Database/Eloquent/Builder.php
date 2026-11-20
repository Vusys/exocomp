<?php

namespace Illuminate\Database\Eloquent;

/**
 * The query builder a model forwards to: what `Order::find()` runs.
 */
class Builder
{
    public function find($id, $columns = ['*'])
    {
        return null;
    }

    public function where($column, $operator = null, $value = null)
    {
        return $this;
    }
}

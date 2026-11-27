<?php

namespace Illuminate\Database\Eloquent\Relations;

/**
 * The methods a has-one-or-many relation declares itself, because they set the foreign key.
 */
abstract class HasOneOrMany extends Relation
{
    public function save($model)
    {
        return $model;
    }

    public function create(array $attributes = [])
    {
        return null;
    }
}

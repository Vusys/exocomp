<?php

namespace Illuminate\Database\Eloquent\Relations;

class BelongsTo extends Relation
{
    public function associate($model)
    {
        return $model;
    }
}

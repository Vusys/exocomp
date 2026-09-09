<?php

namespace Illuminate\Database\Eloquent;

use Illuminate\Database\Eloquent\Concerns\HasAttributes;

/**
 * A stand-in for the framework's base model.
 *
 * Its position in the hierarchy is most of what matters, but not all of it any more: the
 * convention resolver confirms that the installed framework still implements local scopes and
 * accessors before it will resolve a call through either, so `hasNamedScope()` has to be here
 * and `hasGetMutator()` has to be on the trait — which is where the real framework keeps them,
 * and the reason the confirmation walks traits rather than looking at two class names.
 */
abstract class Model
{
    use HasAttributes;

    protected $casts = [];

    public function save(): bool
    {
        return true;
    }

    public function touch(): bool
    {
        return true;
    }

    public function hasNamedScope($scope): bool
    {
        return method_exists($this, 'scope'.ucfirst($scope));
    }
}

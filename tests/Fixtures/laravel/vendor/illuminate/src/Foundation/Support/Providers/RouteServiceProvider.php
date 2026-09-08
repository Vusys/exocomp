<?php

namespace Illuminate\Foundation\Support\Providers;

/**
 * A stand-in for the framework's route provider. It exists so the application's own provider
 * has a parent that can be placed through the autoload map — which is how the root controller
 * namespace is found, and the shape every real vendor base class has.
 */
class RouteServiceProvider
{
    protected $namespace;
}

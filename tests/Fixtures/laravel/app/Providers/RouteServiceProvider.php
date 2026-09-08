<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;

/**
 * The root a string-named controller is relative to. Without reading this, every route written
 * as `'OrderListController'` reports a class that does not exist under that name.
 */
final class RouteServiceProvider extends ServiceProvider
{
    /**
     * @var string
     */
    protected $namespace = 'App\Http\Controllers';
}

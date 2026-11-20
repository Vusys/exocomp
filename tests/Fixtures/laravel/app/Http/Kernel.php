<?php

declare(strict_types=1);

namespace App\Http;

use App\Http\Middleware\Authenticate;
use App\Http\Middleware\RecordRequest;
use App\Http\Middleware\StartTenant;

/**
 * The pre-Laravel-11 place for middleware. `auth` is declared here; `throttle` is left to the
 * framework, so a reader of this application cannot say what it runs.
 */
final class Kernel
{
    /**
     * @var list<class-string>
     */
    protected $middleware = [
        RecordRequest::class,
    ];

    /**
     * @var array<string, list<string>>
     */
    protected $middlewareGroups = [
        'web' => [StartTenant::class],
        'api' => ['throttle:api'],
    ];

    /**
     * @var array<string, class-string>
     */
    protected $middlewareAliases = [
        'auth' => Authenticate::class,
    ];
}

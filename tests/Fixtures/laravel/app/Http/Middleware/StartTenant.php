<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;

/**
 * In the `web` group, which Laravel applies to routes/web.php by convention.
 */
final class StartTenant
{
    public function handle($request, Closure $next): mixed
    {
        return $next($request);
    }
}

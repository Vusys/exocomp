<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;

/**
 * Global middleware: it runs before every route.
 */
final class RecordRequest
{
    public function handle($request, Closure $next): mixed
    {
        return $next($request);
    }
}

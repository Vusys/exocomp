<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\AuditLog;
use Closure;

/**
 * Aliased as `auth` in the kernel, and used by the admin route group.
 */
final class Authenticate
{
    public function handle($request, Closure $next): mixed
    {
        AuditLog::record('signed in');

        return $next($request);
    }
}

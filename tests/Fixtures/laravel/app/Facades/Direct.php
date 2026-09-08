<?php

declare(strict_types=1);

namespace App\Facades;

use App\Services\ReportBuilder;
use Illuminate\Support\Facades\Facade;

/**
 * The one-hop shape: the facade names its class outright, so no container lookup is involved
 * and the answer is resolved rather than inferred.
 */
final class Direct extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ReportBuilder::class;
    }
}

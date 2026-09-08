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

    /**
     * A facade's own static method. The important ones are written this way — `fake()`, `spy()`
     * and `swap()` all live on the facade rather than on the class it resolves to — so looking
     * only at the concrete class reports them as forwarded, which is wrong about a method
     * declared twenty lines above the accessor.
     */
    public static function fake(): void {}
}

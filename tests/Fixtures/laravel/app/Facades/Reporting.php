<?php

declare(strict_types=1);

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * The three-hop shape: the accessor is a container key, and the class it stands for is named in
 * a provider that this file does not mention.
 */
final class Reporting extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'reporting';
    }
}

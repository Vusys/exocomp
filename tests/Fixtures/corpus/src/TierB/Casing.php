<?php

declare(strict_types=1);

namespace Fixtures\TierB;

/**
 * PHP's own asymmetry, and a trap a naive index gets wrong in both directions: class and
 * method names are case-insensitive, while property, constant and enum-case names are not.
 *
 * `$obj->Value` and `$obj->value` below are two different properties. `handle()` and
 * `HANDLE()` would be the same method, which is why only one of them can be declared.
 */
final class Casing
{
    public const string VALUE = 'upper';

    public string $value = 'lower';

    public string $Value = 'capital';

    public function handle(): string
    {
        return $this->value.$this->Value;
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

/**
 * A read of a config key from inside a namespace. An unqualified `config()` here resolves to
 * `App\Services\config` as far as the name resolver is concerned, which is why matching on the
 * resolved name alone finds nothing in any file that has a namespace — that is, in all of them.
 */
final class BillingService
{
    public function key(): string
    {
        return (string) config('services.stripe.key');
    }

    public function timeout(string $name): int
    {
        // The key is computed. This is a read of something under `services`, and reporting no
        // reads without mentioning it would overstate how well the blast radius is known.
        return (int) config("services.{$name}.timeout");
    }
}

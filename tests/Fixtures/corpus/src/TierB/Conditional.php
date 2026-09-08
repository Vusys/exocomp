<?php

declare(strict_types=1);

namespace Fixtures\TierB;

if (! class_exists(Polyfill::class)) {
    /**
     * A class declared inside a conditional. Legal PHP, and common in polyfills — a collector
     * that only walks the top level of a file never sees it.
     */
    final class Polyfill
    {
        public function shim(): bool
        {
            return true;
        }
    }
}

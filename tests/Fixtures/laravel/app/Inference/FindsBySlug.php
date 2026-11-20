<?php

declare(strict_types=1);

namespace App\Inference;

/**
 * `self` in a trait is the class that uses it.
 */
trait FindsBySlug
{
    public static function findBySlug(string $slug): ?self
    {
        return null;
    }

    public function me(): self
    {
        return $this;
    }
}

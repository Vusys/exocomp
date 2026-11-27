<?php

declare(strict_types=1);

namespace App\Inference;

/**
 * A class of the application's with a method named like a collection's: calls on a collection
 * are not its callers.
 */
final class Picker
{
    public function filter(array $criteria): array
    {
        return $criteria;
    }

    public function options(array $json): void
    {
        collect($json)->map(fn ($o) => $o)->filter();
    }

    public function real(self $picker): void
    {
        $picker->filter([]);
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

/**
 * A service with a method named like half of Eloquent's API. Searching for its callers is the
 * case where every `Model::query()->create()` in an application is a candidate.
 */
final class Enrolment
{
    public function create(string $name): string
    {
        return $name;
    }
}

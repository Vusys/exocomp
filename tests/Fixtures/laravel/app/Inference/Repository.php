<?php

declare(strict_types=1);

namespace App\Inference;

/**
 * @template TModel of object
 */
abstract class Repository
{
    /** @return list<TModel> */
    public function all(): array
    {
        return [];
    }

    /** @return static */
    public function fluent(): self
    {
        return $this;
    }
}

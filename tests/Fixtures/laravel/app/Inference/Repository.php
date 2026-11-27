<?php

declare(strict_types=1);

namespace App\Inference;

/**
 * @template TModel of object
 */
abstract class Repository
{
    /** @return TModel */
    abstract public function find(int $id): object;

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

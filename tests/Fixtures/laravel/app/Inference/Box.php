<?php

declare(strict_types=1);

namespace App\Inference;

/**
 * @template T
 */
final class Box
{
    /** @param T $value */
    public function __construct(private mixed $value) {}

    /** @return T */
    public function get(): mixed
    {
        return $this->value;
    }
}

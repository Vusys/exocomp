<?php

declare(strict_types=1);

namespace Fixtures\TierA;

use Countable;
use Stringable;

final readonly class Types
{
    public function union(int|string $value): int|false
    {
        return is_int($value) ? $value : false;
    }

    public function intersection(Countable&Stringable $value): string
    {
        return (string) $value;
    }

    public function disjunctiveNormalForm((Countable&Stringable)|null $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    public function nullable(?Contract $contract): ?Contract
    {
        return $contract;
    }

    public function fails(string $why): never
    {
        throw new \RuntimeException($why);
    }

    public function self(): static
    {
        return $this;
    }

    public function variadic(string $first, int|float ...$rest): array
    {
        return [$first, ...$rest];
    }

    public function byReference(array &$target): void
    {
        $target[] = 1;
    }
}

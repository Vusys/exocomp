<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Inference;

/**
 * Remembers a type worked out once per run, including "nothing found", for a resolver that is
 * otherwise immutable.
 */
final class TypeMemo
{
    /** @var array<string, ResolvedType|null> */
    private array $types = [];

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->types);
    }

    public function get(string $key): ?ResolvedType
    {
        return $this->types[$key] ?? null;
    }

    public function set(string $key, ?ResolvedType $type): ?ResolvedType
    {
        return $this->types[$key] = $type;
    }
}

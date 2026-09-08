<?php

declare(strict_types=1);

namespace Fixtures\TierA;

/**
 * PHP 8.4 syntax, in the corpus from the first commit.
 *
 * Not a nice-to-have: inability to parse property hooks and asymmetric visibility is exactly
 * why this engine exists rather than a wrapper around an older one. If this file ever stops
 * being understood, the project has become the thing it replaced, and this fixture is the only
 * thing that would say so.
 */
final class Modern
{
    public string $shout {
        get => strtoupper($this->name);
    }

    public private(set) int $version = 1;

    protected protected(set) string $slug = '';

    public string $name {
        get {
            return $this->storedName;
        }
        set (string $value) {
            $this->storedName = trim($value);
        }
    }

    private string $storedName = '';

    public function __construct(public private(set) bool $sealed = false) {}
}

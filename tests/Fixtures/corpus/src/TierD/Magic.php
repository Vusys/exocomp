<?php

declare(strict_types=1);

namespace Fixtures\TierD;

/**
 * The contrast pair the design report calls the most valuable in the corpus.
 *
 * `Bag` and `AnnotatedBag` have identical bodies. The only difference is that one of them
 * writes down what its magic does. Anything reached through `Bag` must come back `textual`,
 * and the same call through `AnnotatedBag` may come back `inferred` and must never come back
 * `resolved` — an annotation is a claim about the code, not a fact of it.
 *
 * If these two ever produce the same confidence, the confidence model has collapsed and the
 * write side is unsafe, whichever direction they collapsed in.
 */
class Bag
{
    /** @var array<string, mixed> */
    private array $items = [];

    public function __call(string $name, array $arguments): mixed
    {
        return $this->items[$name] ?? null;
    }

    public function __get(string $name): mixed
    {
        return $this->items[$name] ?? null;
    }
}

/**
 * The same class, with its magic declared.
 *
 * @method Notifier fetch(string $key)
 * @method static Notifier make()
 * @property-read Notifier $current
 * @property Notifier $replaceable
 */
class AnnotatedBag
{
    /** @var array<string, mixed> */
    private array $items = [];

    public function __call(string $name, array $arguments): mixed
    {
        return $this->items[$name] ?? null;
    }

    public function __get(string $name): mixed
    {
        return $this->items[$name] ?? null;
    }
}

/**
 * A class whose whole API is borrowed. `@mixin` says "my magic forwards to that", which turns
 * an unknowable call into a member lookup on a named class — still a claim, so still inferred.
 *
 * @mixin Notifier
 */
class MixinBag
{
    public function __call(string $name, array $arguments): mixed
    {
        return null;
    }
}

/**
 * The one shape of `__call` that is readable: a single statement forwarding to a typed target.
 * Anything more involved than this must be declined rather than half-evaluated.
 */
class Forwarder
{
    public function __construct(private readonly Notifier $inner) {}

    public function __call(string $name, array $arguments): mixed
    {
        return $this->inner->{$name}(...$arguments);
    }
}

/**
 * A `__call` that computes its target. Nothing static can follow this, and it must stay
 * textual no matter how good the forwarder analysis gets.
 */
class Computed
{
    /** @var array<string, Notifier> */
    private array $handlers = [];

    public function __call(string $name, array $arguments): mixed
    {
        $handler = $this->handlers[substr($name, 3)] ?? null;

        return $handler?->send($name);
    }
}

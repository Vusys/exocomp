<?php

declare(strict_types=1);

namespace Fixtures\TierD;

final class NotifierCallers
{
    public function __construct(
        private readonly Notifier $notifier,
        private readonly LoudNotifier $loud,
        private readonly Envelope $envelope,
    ) {}

    /** Declared type is the class itself. */
    public function direct(): bool
    {
        return $this->notifier->send('hello');
    }

    /** Declared type is a subclass, so this is still the same member. */
    public function throughSubclass(): bool
    {
        return $this->loud->send('hello');
    }

    /** A different class entirely. This must never be reported. */
    public function impostor(): bool
    {
        return $this->envelope->send('hello');
    }

    /** No type anywhere, so the tool must admit it does not know. */
    public function untyped($anything): bool
    {
        return $anything->send('hello');
    }

    /** Established by assignment from a constructor call. */
    public function fromNew(): bool
    {
        $made = new Notifier;

        return $made->send('hello');
    }

    /** Established through a docblock, which is worth reporting but never certain. */
    public function fromDocblock(object $thing): bool
    {
        /** @var Notifier $thing */
        return $thing->send('hello');
    }

    /** A variable method name: unknowable, and it must say so. */
    public function dynamic(string $method): bool
    {
        return $this->notifier->{$method}('hello');
    }

    /** A declared parameter type — by far the commonest case in code that writes its types. */
    public function viaParameter(Notifier $given): bool
    {
        return $given->send('hello');
    }
}

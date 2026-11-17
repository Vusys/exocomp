<?php

declare(strict_types=1);

namespace Fixtures\TierD;

/**
 * Arrays whose docblocks say what they hold. The array is no receiver, but what is read out of
 * it is.
 */
final class ArrayCallers
{
    /** @var Notifier[] */
    private array $notifiers = [];

    /** A key of a documented shape. */
    public function viaShapeKey(): bool
    {
        return $this->pair()['primary']->send('hello');
    }

    /** A `foreach` over a documented property. */
    public function viaForeach(): void
    {
        foreach ($this->notifiers as $notifier) {
            $notifier->send('hello');
        }
    }

    /**
     * A documented parameter.
     *
     * @param  list<Notifier>  $notifiers
     */
    public function viaParam(array $notifiers): bool
    {
        return $notifiers[0]->send('hello');
    }

    /** An inline annotation. */
    public function viaInlineVar(): bool
    {
        /** @var array<string, Notifier> $byName */
        $byName = $this->load();

        return $byName['ops']->send('hello');
    }

    /** A key the shape doesn't name stays unknown. */
    public function viaMissingKey(): bool
    {
        return $this->pair()['other']->send('hello');
    }

    /**
     * @return array{primary: Notifier, label: string}
     */
    public function pair(): array
    {
        return ['primary' => new Notifier, 'label' => 'x'];
    }

    public function load(): mixed
    {
        return [];
    }
}

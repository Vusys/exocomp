<?php

declare(strict_types=1);

namespace App\Inference;

/**
 * One method per shape. Each one that is typed calls Gateway::refund; the rest say why not.
 */
final class InferenceProbes
{
    private ?Gateway $cached = null;

    public function __construct(private Holder $holder) {}

    public function callableInvoked(): void
    {
        $f = $this->holder->make(...);
        $f()->refund(1);
    }

    public function callableAsObject(): void
    {
        $f = $this->holder->make(...);
        $f->refund(2);
    }

    public function caught(): void
    {
        try {
            $this->holder->make();
        } catch (GatewayFailed $e) {
            $e->gateway()->refund(3);
        }
    }

    public function traitSelfStatic(): void
    {
        Sluggable::findBySlug('x')?->gateway()->refund(4);
    }

    public function traitSelfInstance(Sluggable $s): void
    {
        $s->me()->gateway()->refund(5);
    }

    public function matched(bool $x): void
    {
        $g = match ($x) {
            true => new Gateway,
            false => new Gateway,
        };
        $g->refund(6);
    }

    public function matchTrue(object $g): void
    {
        match (true) {
            $g instanceof Gateway => $g->refund(7),
            default => null,
        };
    }

    public function coalesceAssigned(): void
    {
        ($this->cached ??= new Gateway)->refund(8);
    }

    public function enumFrom(string $s): void
    {
        Settlement::from($s)->gateway()->refund(9);
    }

    public function closureParameter(): void
    {
        array_map(fn (Holder $h) => $h->make()->refund(10), []);
    }

    public function piped(): void
    {
        $x = $this->holder |> $this->unwrap(...);
        $x->refund(11);
    }

    public function pipedArrow(): void
    {
        $x = $this->holder |> (fn (Holder $h) => $h->make());
        $x->refund(12);
    }

    public function clonedWith(): void
    {
        $h = clone ($this->holder, ['x' => 1]);
        $h->make()->refund(13);
    }

    private function unwrap(Holder $h): Gateway
    {
        return $h->make();
    }
}

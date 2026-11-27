<?php

declare(strict_types=1);

namespace App\Inference;

use App\Models\Invoice;

/**
 * Eloquent chains through relations, scopes, conditionals, callbacks and factory states.
 */
final class EloquentProbes
{
    public function untypedRelation(Shipment $s): void
    {
        $s->invoices()->where('a', 1)->first()->send();
    }

    public function relationSave(Shipment $s, Invoice $i): void
    {
        $s->invoices()->save($i);
    }

    public function relationAssociate(Shipment $s): void
    {
        $s->order()->associate(null);
    }

    public function attributeScope(): void
    {
        Shipment::query()->paid()->first()->order();
    }

    public function attributeScopeStatic(): void
    {
        Shipment::paid()->first()->order();
    }

    public function legacyScope(): void
    {
        Shipment::query()->open()->first()->order();
    }

    public function forwarded(): void
    {
        Shipment::find(1)->order();
    }

    public function conditional(bool $b): void
    {
        Shipment::query()->when($b, fn ($q) => $q->where('a', 1))->first()->order();
    }

    public function eachCallback(): void
    {
        Shipment::query()->each(fn ($shipment) => $shipment->order());
    }

    public function tapped(): void
    {
        tap(new Gateway, fn ($g) => $g->refund(1))->refund(2);
    }

    public function untypedState(): void
    {
        Invoice::factory()->overdue()->create()->send();
    }
}

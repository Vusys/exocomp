<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Invoice;

/**
 * `send()` reached through each shape of Eloquent chain an application writes, none of which
 * declares a type the index holds. Each method is one shape, so a test can name it.
 */
final class InvoiceChains
{
    public function factory(): void
    {
        Invoice::factory()->create()->send();
    }

    public function factoryState(): void
    {
        Invoice::factory()->paid()->for(new Invoice)->create()->send();
    }

    public function factoryMany(): void
    {
        Invoice::factory()->count(3)->create()->first()->send();
    }

    public function find(): void
    {
        Invoice::find(1)->send();
    }

    public function findMany(): void
    {
        Invoice::find([1, 2])->first()->send();
    }

    public function query(): void
    {
        Invoice::query()->where('total', '>', 0)->overdue()->latest()->firstOrFail()->send();
    }

    public function assigned(): void
    {
        $query = Invoice::query()->whereNull('sent_at');
        $invoice = $query->first();
        $invoice->send();
    }

    public function foreachAll(): void
    {
        foreach (Invoice::all() as $invoice) {
            $invoice->send();
        }
    }

    public function foreachGet(): void
    {
        $invoices = Invoice::query()->with('lines')->get();

        foreach ($invoices as $invoice) {
            $invoice->send();
        }
    }

    public function instance(): void
    {
        (new Invoice)->fresh()->send();
    }

    /** Not Eloquent at all: an application class whose fluent methods return `static` and `self`. */
    public function fluent(): void
    {
        (new ReportQuery)->since('2026-01-01')->latest()->invoice()->send();
    }

    /** The method's own parameter, used after a closure has come and gone. */
    public function afterClosure(Invoice $invoice): void
    {
        array_map(static fn (int $id): int => $id, [1]);
        $invoice->send();
    }

    /** An arrow function sees the scope it is written in. */
    public function insideArrow(Invoice $invoice): void
    {
        array_map(fn (): mixed => $invoice->send(), [1]);
    }

    /** A closure sees what it `use`s. */
    public function insideClosureUse(Invoice $invoice): void
    {
        array_map(function () use ($invoice): void {
            $invoice->send();
        }, [1]);
    }

    /** And nothing it does not: without `use`, this `$invoice` is a different, undefined variable. */
    public function insideClosureWithoutUse(Invoice $invoice): void
    {
        array_map(function (): void {
            $invoice->send();
        }, [1]);
    }

    /** A callback's result can be anything at all. */
    public function escapes(): void
    {
        Invoice::query()->tap(fn ($query) => $query)->first()->send();
    }

    /** A link the typing does not name could return anything. */
    public function unnamed(): void
    {
        Invoice::query()->someMacro()->first()->send();
    }
}

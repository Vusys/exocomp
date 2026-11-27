<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\App;

/**
 * Every spelling of a container lookup, and the Eloquent chains a search for `create` meets.
 */
final class EnrolmentCallers
{
    public function __construct(private readonly mixed $app) {}

    public function helper(): string
    {
        return app(Enrolment::class)->create('helper');
    }

    public function alias(): string
    {
        return resolve(Enrolment::class)->create('alias');
    }

    public function helperMake(): string
    {
        return app()->make(Enrolment::class)->create('helper make');
    }

    public function property(): string
    {
        return $this->app->make(Enrolment::class)->create('property');
    }

    public function facade(): string
    {
        return App::make(Enrolment::class)->create('facade');
    }

    /**
     * Another class out of the container: a same-named method on it is not a reference.
     */
    public function unrelated(): string
    {
        return app(ReportBuilder::class)->create('unrelated');
    }

    /**
     * Eloquent's own API, called on a model. Nothing it returns can be an `Enrolment`.
     */
    public function eloquent(): void
    {
        Order::factory()->create();
        Order::query()->where('reference', 'x')->create(['reference' => 'y']);
        (new Order)->newQuery()->create([]);

        // Declared by the application, on the model — but declared to return a relation.
        Order::query()->first()->lines()->create([]);

        // A builder method the chain typing does not name, so the receiver stays untyped and
        // only the exclusion proof can settle it.
        Order::query()->toBase()->create([]);
    }

    /**
     * The same chains with a way out: a callback, or a value read out of the model, can hand
     * back anything at all, so these stay unresolved.
     */
    public function escapes(): void
    {
        Order::query()->tap(fn ($query) => app('swapped'))->create([]);
        Order::query()->get()->map(fn () => new Enrolment)->first()->create('mapped');
        Order::query()->value('enrolment')->create('stored');
        Order::query()->first()->getAttribute('enrolment')->create('attribute');
    }
}

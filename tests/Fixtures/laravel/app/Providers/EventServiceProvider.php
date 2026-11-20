<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\OrderPlaced;
use App\Listeners\NotifyCustomer;
use App\Models\Invoice;
use App\Observers\InvoiceObserver;
use Illuminate\Support\ServiceProvider;

final class EventServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, list<class-string>>
     */
    protected $listen = [
        OrderPlaced::class => [
            NotifyCustomer::class,
        ],
    ];

    /**
     * @var array<class-string, list<class-string>>
     */
    protected $observers = [
        Invoice::class => [InvoiceObserver::class],
    ];
}

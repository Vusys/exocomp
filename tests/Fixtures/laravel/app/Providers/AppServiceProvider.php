<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\ReportWriter;
use App\Http\Controllers\OrderController;
use App\Models\Customer;
use App\Models\Order;
use App\Observers\OrderObserver;
use App\Policies\CustomerPolicy;
use App\Services\CsvReportWriter;
use App\Services\PdfReportWriter;
use App\Services\ReportBuilder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ReportWriter::class, CsvReportWriter::class);

        $this->app->singleton('reporting', fn (): ReportBuilder => new ReportBuilder('pdf'));

        // A factory that does more than construct one class. Following it would mean evaluating
        // it, which is where a static reader starts inventing answers.
        $this->app->bind('report.writer.chosen', function () {
            return random_int(0, 1) === 1 ? new CsvReportWriter : new PdfReportWriter;
        });

        // Invisible from the consumer's side: OrderController asks for the interface and gets a
        // different implementation from everyone else.
        $this->app->when(OrderController::class)
            ->needs(ReportWriter::class)
            ->give(PdfReportWriter::class);

        $this->app->alias(ReportBuilder::class, 'report.builder');
    }

    public function boot(): void
    {
        Relation::morphMap(config('morph-map'));

        Order::observe(OrderObserver::class);

        Gate::policy(Customer::class, CustomerPolicy::class);
    }
}

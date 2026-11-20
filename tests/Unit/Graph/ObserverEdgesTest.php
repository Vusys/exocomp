<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Graph;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Framework\Laravel\DispatchEdge;
use Vusys\Exocomp\Framework\Laravel\ObserverReader;
use Vusys\Exocomp\Project\Project;
use Vusys\Exocomp\Tests\Support\Cli;

/**
 * A model write runs its observers, which the call site never names. `reaches` follows the write
 * to every observer method the write fires, registered in any of the three ways Laravel takes.
 */
final class ObserverEdgesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function writes(): iterable
    {
        yield 'save() may create, through Order::observe()' => ['saveOrder', 'OrderObserver::created', 'inferred'];
        yield 'delete() always deletes' => ['deleteOrder', 'OrderObserver::deleted', 'resolved'];
        yield 'update() through #[ObservedBy]' => ['renameCustomer', 'CustomerObserver::updated', 'inferred'];
        yield 'delete() through $observers' => ['deleteInvoice', 'InvoiceObserver::deleting', 'resolved'];
    }

    #[DataProvider('writes')]
    public function test_a_write_reaches_the_observer_method_it_fires(string $method, string $observer, string $weakest): void
    {
        $run = Cli::inLaravel(['reaches', 'App\Services\ModelWrites::'.$method, 'App\Services\AuditLog::record']);

        $this->assertSame(0, $run->exitCode, $run->output);
        $this->assertStringContainsString('observed  →  App\Observers\\'.$observer, $run->output);
        $this->assertStringContainsString('the weakest step is '.$weakest, $run->output);
    }

    public function test_a_mass_update_fires_no_model_events(): void
    {
        $run = Cli::inLaravel(['reaches', 'App\Services\ModelWrites::massUpdateOrders', 'App\Services\AuditLog::record']);

        // No observer step, so no path; the framework call it stops at makes the "no" incomplete.
        $this->assertSame(3, $run->exitCode);
    }

    public function test_a_method_that_is_not_a_write_fires_nothing(): void
    {
        $run = Cli::inLaravel(['reaches', 'App\Services\ModelWrites::sendInvoice', 'App\Services\AuditLog::record']);

        $this->assertSame(1, $run->exitCode);
    }

    public function test_every_registration_is_read_and_only_for_its_own_model(): void
    {
        $project = Project::at(Cli::laravelRoot());
        $paths = ['app/Providers/AppServiceProvider.php', 'app/Providers/EventServiceProvider.php', 'app/Models/Customer.php'];
        $observers = static fn (string $model): array => array_map(
            static fn (DispatchEdge $edge): string => $edge->counterpart.' — '.$edge->note,
            new ObserverReader()->read($model, $paths, $project->path(...)),
        );

        $this->assertSame(['App\Observers\OrderObserver — registered by Order::observe()'], $observers('App\Models\Order'));
        $this->assertSame(['App\Observers\CustomerObserver — registered by #[ObservedBy] on the model'], $observers('App\Models\Customer'));
        $this->assertSame(['App\Observers\InvoiceObserver — registered in $observers'], $observers('App\Models\Invoice'));
        $this->assertSame([], $observers('App\Models\OrderLine'));
    }
}

<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Framework;

use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Tests\Support\Cli;
use Vusys\Exocomp\Tests\Support\Run;
use Vusys\Exocomp\Tests\Support\TemporaryProject;

/**
 * Laravel registrations that were read wrongly or not at all: route shapes, a capped sweep,
 * Blueprint helpers with implied names, a morph map keyed by constants, bindings built by a
 * container lookup, dispatch shapes and listener registrations, and the model's own attributes.
 */
final class LaravelWiringTest extends TestCase
{
    private TemporaryProject $project;

    protected function setUp(): void
    {
        $this->project = TemporaryProject::copyOfLaravel();
    }

    protected function tearDown(): void
    {
        $this->project->destroy();
    }

    public function test_routes_are_the_table_laravel_lists(): void
    {
        $this->project->write('exocomp-routes.json', (string) json_encode([
            ['method' => 'GET|HEAD|POST', 'uri' => 'both', 'name' => null, 'action' => 'App\Http\Controllers\OrderController@store', 'middleware' => ['web'], 'path' => null],
            ['method' => 'GET|HEAD', 'uri' => 'admin/reports', 'name' => 'admin.reports', 'action' => 'App\Modules\Admin\ReportController@index', 'middleware' => ['web', 'auth'], 'path' => null],
            ['method' => 'GET|HEAD', 'uri' => 'ping', 'name' => null, 'action' => 'Closure', 'middleware' => ['web'], 'path' => 'app/Modules/Admin/routes.php:7'],
        ]));

        $all = $this->exocomp(['routes', '--max-tokens', '0'])->output;

        // A route a provider loads, with the prefix and namespace it wraps the file in, is
        // in the table as the framework registered it.
        $this->assertStringContainsString('/admin/reports', $all);
        $this->assertStringContainsString('App\Modules\Admin\ReportController::index', $all);
        $this->assertStringContainsString('app/Modules/Admin/routes.php:7', $all);
        $this->assertStringContainsString('/both', $this->exocomp(['routes', '--method', 'POST'])->output);
        $this->assertStringNotContainsString('none matches', $this->exocomp(['reaches', 'route:POST /both', '::neverCalledAnywhere'])->errors);
    }

    public function test_the_route_cache_is_read_without_running_it(): void
    {
        @unlink($this->project->path('exocomp-routes.json'));
        $this->project->write('bootstrap/cache/routes-v7.php', <<<'PHP'
            <?php

            app('router')->setCompiledRoutes(
                array (
              'compiled' => array (),
              'attributes' =>
              array (
                'orders.store' =>
                array (
                  'methods' => array (0 => 'POST'),
                  'uri' => 'orders',
                  'action' => array (
                    'uses' => 'App\\Http\\Controllers\\OrderController@store',
                    'controller' => 'App\\Http\\Controllers\\OrderController@store',
                    'middleware' => array (0 => 'web'),
                    'as' => 'orders.store',
                  ),
                ),
              ),
            )
            );
            PHP);

        $output = $this->exocomp(['routes'])->output;

        $this->assertStringContainsString('routes read from bootstrap/cache/routes-v7.php', $output);
        $this->assertMatchesRegularExpression('#POST\s+/orders\s+App\\\\Http\\\\Controllers\\\\OrderController::store#', $output);
    }

    public function test_without_a_route_table_the_route_commands_refuse(): void
    {
        @unlink($this->project->path('exocomp-routes.json'));

        $run = $this->exocomp(['routes']);

        $this->assertSame(2, $run->exitCode);
        $this->assertStringContainsString('php artisan route:list --json > exocomp-routes.json', $run->errors);
        $this->assertSame(2, $this->exocomp(['reaches', 'route:*', '::neverCalledAnywhere'])->exitCode);
    }

    public function test_a_table_older_than_the_route_files_says_so(): void
    {
        touch($this->project->path('exocomp-routes.json'), time() - 3600);
        touch($this->project->path('routes/web.php'), time());

        $this->assertStringContainsString('may be out of date', $this->exocomp(['routes'])->output);
    }

    public function test_a_capped_sweep_says_how_many_routes_it_did_not_walk(): void
    {
        $output = $this->exocomp(['reaches', 'route:*', '::neverCalledAnywhere', '--max-routes', '1'])->output;

        $this->assertMatchesRegularExpression('/0 of 1 starting point reach ::neverCalledAnywhere; \d+ more matched and were not walked/', $output);
    }

    public function test_blueprint_helpers_with_implied_names_and_index_names(): void
    {
        $this->project->write('database/migrations/2025_01_01_000000_create_widgets.php', <<<'PHP'
            <?php

            use App\Models\Customer;
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    Schema::create('widgets', function (Blueprint $table) {
                        $table->id();
                        $table->uuid()->unique();
                        $table->ulid();
                        $table->ipAddress();
                        $table->macAddress();
                        $table->foreignIdFor(Customer::class);
                        $table->morphs('owner');
                        $table->softDeletes();
                        $table->timestamps();
                        $table->index(['id', 'ulid'], 'widgets_lookup');
                    });

                    Schema::table('widgets', function (Blueprint $table) {
                        $table->dropSoftDeletes();
                        $table->dropTimestamps();
                    });
                }
            };
            PHP);

        $output = $this->exocomp(['schema', 'widgets', '--max-tokens', '0'])->output;

        foreach (['uuid', 'ulid', 'ip_address', 'mac_address', 'customer_id', 'owner_type'] as $column) {
            $this->assertMatchesRegularExpression('/\s'.$column.'\s/', $output, $column);
        }

        foreach (['deleted_at', 'created_at', 'App\Models\Customer'] as $gone) {
            $this->assertStringNotContainsString($gone, $output, $gone);
        }

        $this->assertMatchesRegularExpression('/index\s+id, ulid\s+widgets_lookup/', $output);
        $this->assertMatchesRegularExpression('/unique\s+uuid/', $output);
        $this->assertMatchesRegularExpression('/index\s+owner_type, owner_id/', $output);
    }

    public function test_a_morph_map_key_written_as_a_class_constant_is_read(): void
    {
        $this->project->write('config/morph-map.php', <<<'PHP'
            <?php

            use App\Models\Comment;
            use App\Models\Order;

            return [
                'order' => Order::class,
                Comment::MORPH_KEY => Comment::class,
            ];
            PHP);
        $comment = (string) file_get_contents($this->project->path('app/Models/Comment.php'));
        $this->project->write('app/Models/Comment.php', (string) preg_replace('/(class Comment[^{]*\{)/', "$1\n    public const MORPH_KEY = 'note';\n", $comment, 1));

        $output = $this->exocomp(['morph-map'])->output;

        $this->assertStringContainsString('2 morph map entries', $output);
        $this->assertMatchesRegularExpression('/note\s+App\\\\Models\\\\Comment/', $output);
    }

    public function test_bindings_built_by_a_lookup_by_a_constant_or_as_an_instance(): void
    {
        $this->project->write('app/Providers/WiringServiceProvider.php', <<<'PHP'
            <?php

            namespace App\Providers;

            use App\Services\CsvReportWriter;
            use Illuminate\Support\ServiceProvider;

            final class WiringServiceProvider extends ServiceProvider
            {
                public const KEY = 'reports.writer';

                public function register(): void
                {
                    $this->app->singleton('reports', fn ($app) => $app->make(CsvReportWriter::class));
                    $this->app->bind(self::KEY, CsvReportWriter::class);
                    $this->app->instance('reports.instance', new CsvReportWriter);
                }
            }
            PHP);

        $output = $this->exocomp(['bindings', '--max-tokens', '0'])->output;

        $this->assertMatchesRegularExpression('/reports\s+→\s+App\\\\Services\\\\CsvReportWriter\s+the factory resolves it from the container/', $output);
        $this->assertMatchesRegularExpression('/reports\.writer\s+→\s+App\\\\Services\\\\CsvReportWriter/', $output);
        $this->assertMatchesRegularExpression('/reports\.instance\s+→\s+App\\\\Services\\\\CsvReportWriter/', $output);
    }

    public function test_dispatch_shapes_and_listener_registrations(): void
    {
        $this->project->write('app/Wiring/Sends.php', <<<'PHP'
            <?php

            namespace App\Wiring;

            use App\Jobs\ProcessOrder;
            use Illuminate\Support\Facades\Bus;
            use Illuminate\Support\Facades\Queue;

            final class Sends
            {
                public function chain(): void { Bus::chain([new ProcessOrder, new ProcessOrder])->dispatch(); }
                public function batch(): void { Bus::batch([new ProcessOrder])->dispatch(); }
                public function queue(): void { Queue::push(new ProcessOrder); }
                public function variable(): void { $job = new ProcessOrder; dispatch($job); }
            }
            PHP);

        $output = $this->exocomp(['dispatches', 'App\Jobs\ProcessOrder', '--max-tokens', '0'])->output;

        foreach (['Bus::chain()', 'Bus::batch()', 'Queue::push()'] as $sender) {
            $this->assertStringContainsString($sender, $output, $sender);
        }

        $this->assertMatchesRegularExpression('#app/Wiring/Sends\.php:\d+\s+dispatch\(\)#', $output);
    }

    public function test_the_model_lists_local_scopes_and_its_table_attribute(): void
    {
        $this->project->write('app/Wiring/Ledgered.php', <<<'PHP'
            <?php

            namespace App\Wiring;

            use Illuminate\Database\Eloquent\Attributes\Scope;
            use Illuminate\Database\Eloquent\Attributes\Table;
            use Illuminate\Database\Eloquent\Model;

            #[Table('legacy_ledgers')]
            final class Ledgered extends Model
            {
                #[Scope]
                protected function open($query): void {}

                public function scopeClosed($query): void {}
            }
            PHP);

        $output = $this->exocomp(['model', 'App\Wiring\Ledgered'])->output;

        $this->assertMatchesRegularExpression('/table\s+legacy_ledgers\s+#\[Table\]/', $output);
        $this->assertStringContainsString('#[Scope] open()', $output);
        $this->assertStringContainsString('scopeClosed()', $output);
    }

    /**
     * @param  list<string>  $arguments
     */
    private function exocomp(array $arguments): Run
    {
        return Cli::run([...$arguments, '--project', $this->project->root]);
    }
}

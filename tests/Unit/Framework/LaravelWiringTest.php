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

    public function test_route_shapes_expand_to_what_the_framework_registers(): void
    {
        $this->project->write('routes/shapes.php', <<<'PHP'
            <?php

            use App\Http\Controllers\OrderController;
            use Illuminate\Support\Facades\Route;

            Route::match(['get', 'post'], '/both', [OrderController::class, 'store']);
            Route::any('/anything', [OrderController::class, 'index']);
            Route::resources(['bulk-orders' => OrderController::class]);
            Route::singleton('profile', OrderController::class);
            Route::view('/welcome', 'welcome');
            PHP);

        $all = $this->exocomp(['routes', '--max-tokens', '0'])->output;
        $post = $this->exocomp(['routes', '--method', 'POST', '--max-tokens', '0'])->output;

        $this->assertStringContainsString('GET|POST', $all);
        $this->assertStringContainsString('GET|HEAD|POST|PUT|PATCH|DELETE|OPTIONS', $all);
        $this->assertStringContainsString('/bulk-orders/{bulk_order}/edit', $all);
        $this->assertStringContainsString('/profile/edit', $this->exocomp(['routes', 'profile'])->output);
        $this->assertStringContainsString('a view or a redirect', $all);
        $this->assertStringContainsString('/both', $post);
        $this->assertStringContainsString('/anything', $post);
        $this->assertStringNotContainsString('none matches', $this->exocomp(['reaches', 'route:POST /both', '::neverCalledAnywhere'])->errors);
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

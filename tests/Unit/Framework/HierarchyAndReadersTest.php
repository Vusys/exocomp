<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Framework;

use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Tests\Support\Cli;
use Vusys\Exocomp\Tests\Support\Run;
use Vusys\Exocomp\Tests\Support\TemporaryProject;

/**
 * Hierarchy and reader gaps: trait `as` and `insteadof`, `overrides` in both directions and into
 * a dependency, config reads through the repository and writes, schema on other connections and
 * after renames, accessors by their attribute name, casts, and bindings by key.
 */
final class HierarchyAndReadersTest extends TestCase
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

    public function test_trait_aliases_and_insteadof(): void
    {
        $this->project->write('app/Adapted/Pings.php', "<?php\nnamespace App\\Adapted;\ntrait Pings { public function hello(): string { return 'ping'; } }\n");
        $this->project->write('app/Adapted/Greets.php', "<?php\nnamespace App\\Adapted;\ntrait Greets { public function hello(): string { return 'hi'; } protected function secret(): string { return 's'; } }\n");
        $this->project->write('app/Adapted/Host.php', "<?php\nnamespace App\\Adapted;\nclass Host { use Pings, Greets { Pings::hello insteadof Greets; Greets::hello as greet; secret as public revealed; } }\n");
        $this->project->write('app/Adapted/Calls.php', "<?php\nnamespace App\\Adapted;\nclass Calls {\n    public function plain(Host \$h): string { return \$h->hello(); }\n    public function aliased(Host \$h): string { return \$h->greet(); }\n    public function revealed(Host \$h): string { return \$h->revealed(); }\n}\n");

        $this->assertStringContainsString('App\Adapted\Greets::hello', $this->exocomp(['calls-from', 'App\Adapted\Calls::aliased'])->output);
        $this->assertStringContainsString('App\Adapted\Greets::secret', $this->exocomp(['calls-from', 'App\Adapted\Calls::revealed'])->output);

        $greets = $this->exocomp(['callers-of', 'App\Adapted\Greets::hello'])->output;

        $this->assertStringContainsString('App\Adapted\Calls::aliased', $greets);
        $this->assertStringNotContainsString('App\Adapted\Calls::plain', $greets);
        $this->assertStringContainsString('App\Adapted\Calls::plain', $this->exocomp(['callers-of', 'App\Adapted\Pings::hello'])->output);
        $this->assertStringContainsString('App\Adapted\Calls::revealed', $this->exocomp(['callers-of', 'App\Adapted\Greets::secret'])->output);

        $members = $this->exocomp(['members', 'App\Adapted\Host'])->output;

        $this->assertStringContainsString('aliased from traits (2)', $members);
        $this->assertStringContainsString('public function greet(): string  (App\Adapted\Greets::hello)', $members);
        $this->assertStringContainsString('public function revealed(): string  (App\Adapted\Greets::secret)', $members);
        $this->assertSame(0, $this->exocomp(['members', 'App\Adapted\Host::greet'])->exitCode);
    }

    public function test_overrides_down_from_an_interface_and_up_into_a_dependency(): void
    {
        $down = $this->exocomp(['overrides', 'App\Contracts\ReportWriter::write'])->output;

        $this->assertStringContainsString('implements it, in App\Services\CsvReportWriter', $down);

        $up = $this->exocomp(['overrides', 'App\Models\Customer::save'])->output;

        $this->assertStringContainsString('Illuminate\Database\Eloquent\Model', $up);
        $this->assertStringContainsString('in a dependency', $up);
        // A dependency's class is placed and its declaration listed; nothing in the app
        // overrides it, so that is a "none", exit 1.
        $vendor = $this->exocomp(['overrides', 'Illuminate\Database\Eloquent\Model::save']);

        $this->assertStringContainsString('vendor/illuminate/src/Database/Eloquent/Model.php:22:5', $vendor->output);
        $this->assertSame(1, $vendor->exitCode);
    }

    public function test_config_reads_through_the_repository_and_writes_apart(): void
    {
        $this->project->write('config/payments.php', "<?php\nreturn ['driver' => 'stripe'];\n");
        $this->project->write('app/Configured/Reads.php', <<<'PHP'
            <?php

            namespace App\Configured;

            final class Reads
            {
                public function __construct(private $config) {}

                public function a() { return config()->get('payments.driver'); }
                public function b() { return app('config')->get('payments.driver'); }
                public function c() { return $this->config->get('payments.driver'); }
                public function d($app) { $payments = $app['config']['payments']; return $payments['driver']; }
                public function e() { \Illuminate\Support\Facades\Config::set('payments.driver', 'fake'); }
                public function f() { config(['payments.driver' => 'fake']); }
            }
            PHP);

        $output = $this->exocomp(['config', 'payments.driver'])->output;

        $this->assertMatchesRegularExpression('/(\d+) reads/', $output);
        foreach (['config()->get', "app('config')->get", '->config->get', '$payments[...]'] as $read) {
            $this->assertStringContainsString($read, $output, $read);
        }

        [, $written] = explode('written', $output, 2) + ['', ''];

        $this->assertStringContainsString('Config::set', $written);
        $this->assertStringContainsString('config([...])', $written);
    }

    public function test_schema_follows_connections_renames_drops_and_raw_sql(): void
    {
        $this->project->write('database/migrations/2025_06_01_000000_reshape.php', <<<'PHP'
            <?php

            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\DB;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    Schema::create('parts', function (Blueprint $table) { $table->id(); });
                    Schema::rename('parts', 'components');
                    DB::statement('ALTER TABLE components ADD COLUMN raw_added VARCHAR(10)');
                    Schema::connection('reporting')->create('report_rows', function (Blueprint $table) { $table->id(); });
                    Schema::create('conditional', function (Blueprint $table) {
                        $table->id();
                        if (config('features.extra')) { $table->string('maybe'); }
                    });
                }
            };
            PHP);

        $this->assertStringContainsString('"reporting" connection', $this->exocomp(['schema', 'report_rows'])->output);
        $this->assertStringContainsString('renamed to components', $this->exocomp(['schema', 'parts'])->errors);
        $this->assertStringContainsString('raw SQL', $this->exocomp(['schema', 'components'])->output);
        $this->assertStringNotContainsString('raw SQL', $this->exocomp(['schema', 'conditional'])->output);
        $this->assertStringContainsString('under a condition', $this->exocomp(['schema', 'conditional'])->output);
    }

    public function test_an_attribute_cast_types_its_read_and_an_accessor_is_shown_by_its_attribute(): void
    {
        $this->project->write('app/Casted/Status.php', "<?php\nnamespace App\\Casted;\nenum Status: string { case Paid = 'paid'; public function label(): string { return 'x'; } }\n");
        $this->project->write('app/Casted/Bill.php', "<?php\nnamespace App\\Casted;\nuse Illuminate\\Database\\Eloquent\\Model;\nclass Bill extends Model {\n    protected \$casts = ['status' => Status::class];\n    public function getShippingAddressAttribute(): string { return 'x'; }\n    public function label(Bill \$b): string { return \$b->status->label(); }\n}\n");

        $this->assertStringContainsString('App\Casted\Status::label', $this->exocomp(['calls-from', 'App\Casted\Bill::label'])->output);

        $shown = $this->exocomp(['show', 'App\Casted\Bill::shippingAddress']);

        $this->assertSame(0, $shown->exitCode, $shown->errors);
        $this->assertStringContainsString('getShippingAddressAttribute', $shown->output);
    }

    public function test_declared_bindings_tags_extensions_and_keys(): void
    {
        $this->project->write('app/Providers/KeyedServiceProvider.php', <<<'PHP'
            <?php

            namespace App\Providers;

            use App\Contracts\ReportWriter;
            use App\Services\CsvReportWriter;
            use Illuminate\Support\ServiceProvider;

            final class KeyedServiceProvider extends ServiceProvider
            {
                public $singletons = ['reports.csv' => CsvReportWriter::class];

                public function register(): void
                {
                    $this->app->tag([CsvReportWriter::class], 'writers');
                    $this->app->extend(ReportWriter::class, fn ($writer) => $writer);
                }
            }
            PHP);
        $this->project->write('app/Keyed/Uses.php', "<?php\nnamespace App\\Keyed;\nclass Uses { public function a(): void { app('reports.csv')->write(); } }\n");

        $bindings = $this->exocomp(['bindings', '--max-tokens', '0'])->output;

        $this->assertMatchesRegularExpression('/singleton\s+reports\.csv\s+→\s+App\\\\Services\\\\CsvReportWriter\s+declared in \$singletons/', $bindings);
        $this->assertMatchesRegularExpression('/tag\s+writers\s+→\s+App\\\\Services\\\\CsvReportWriter/', $bindings);
        $this->assertMatchesRegularExpression('/extend\s+App\\\\Contracts\\\\ReportWriter/', $bindings);
        $this->assertStringContainsString('App\Services\CsvReportWriter::write', $this->exocomp(['calls-from', 'App\Keyed\Uses::a'])->output);
    }

    /**
     * @param  list<string>  $arguments
     */
    private function exocomp(array $arguments): Run
    {
        return Cli::run([...$arguments, '--project', $this->project->root]);
    }
}

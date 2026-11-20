<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Graph;

use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Framework\Laravel\MiddlewareReader;
use Vusys\Exocomp\Project\Project;
use Vusys\Exocomp\Symbol\Confidence;
use Vusys\Exocomp\Tests\Support\Cli;

/**
 * A route runs its middleware before its controller. A walk from a route starts at both.
 */
final class MiddlewareEdgesTest extends TestCase
{
    public function test_an_alias_on_the_route_is_walked_into(): void
    {
        $run = Cli::inLaravel(['reaches', 'route:GET /admin/orders', 'App\Services\AuditLog::record']);

        $this->assertSame(0, $run->exitCode, $run->output);
        $this->assertStringContainsString('middleware  →  App\Http\Middleware\Authenticate::handle', $run->output);
        $this->assertStringContainsString('the weakest step is resolved', $run->output);
    }

    public function test_the_group_a_route_file_gets_by_convention_is_inferred(): void
    {
        $run = Cli::inLaravel(['reaches', 'route:POST /orders', 'App\Http\Middleware\StartTenant::handle']);

        $this->assertSame(0, $run->exitCode, $run->output);
        $this->assertStringContainsString('the weakest step is inferred', $run->output);
    }

    public function test_global_middleware_runs_on_every_route_and_a_group_only_on_its_own(): void
    {
        $global = Cli::inLaravel(['reaches', 'route:GET /api/orders', 'App\Http\Middleware\RecordRequest::handle']);
        $web = Cli::inLaravel(['reaches', 'route:GET /api/orders', 'App\Http\Middleware\StartTenant::handle']);

        $this->assertSame(0, $global->exitCode, $global->output);
        $this->assertSame(1, $web->exitCode, $web->output);
    }

    public function test_a_name_only_the_framework_defines_is_said_rather_than_dropped(): void
    {
        $run = Cli::inLaravel(['reaches', 'route:GET /api/orders', 'App\Http\Middleware\*']);

        $this->assertStringContainsString('middleware the application does not declare (throttle) was not followed', $run->output);
    }

    public function test_a_symbol_start_runs_no_middleware(): void
    {
        $run = Cli::inLaravel(['reaches', 'App\Http\Controllers\OrderController::index', 'App\Http\Middleware\*']);

        $this->assertSame(1, $run->exitCode);
    }

    public function test_bootstrap_app_is_read_as_well_as_a_kernel(): void
    {
        $root = sys_get_temp_dir().'/exocomp-middleware-'.bin2hex(random_bytes(4));
        mkdir($root.'/bootstrap', recursive: true);
        file_put_contents($root.'/bootstrap/app.php', <<<'PHP'
            <?php

            use App\Http\Middleware\Audit;
            use App\Http\Middleware\Locale;
            use App\Http\Middleware\Tenant;
            use App\Http\Middleware\Trace;

            return Application::configure(basePath: dirname(__DIR__))
                ->withMiddleware(function (Middleware $middleware) {
                    $middleware->alias(['audit' => Audit::class]);
                    $middleware->append(Trace::class);
                    $middleware->web(append: [Locale::class]);
                    $middleware->group('tenant', [Tenant::class, 'audit:strict']);
                })
                ->create();
            PHP);

        [$runs, $unresolved] = MiddlewareReader::forProject(Project::at($root))->forRoute(['tenant', 'can:update,order'], 'routes/web.php');

        $this->assertSame(
            [
                ['App\Http\Middleware\Trace', Confidence::Resolved, 'global middleware'],
                ['App\Http\Middleware\Locale', Confidence::Inferred, 'the "web" group, which Laravel applies to web.php by convention'],
                ['App\Http\Middleware\Tenant', Confidence::Resolved, 'on the route'],
                ['App\Http\Middleware\Audit', Confidence::Resolved, 'on the route'],
            ],
            $runs,
        );
        $this->assertSame(['can'], $unresolved);
    }
}

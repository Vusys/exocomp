<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Tests\Support\Cli;
use Vusys\Exocomp\Tests\Support\TemporaryProject;

/**
 * Answers that looked complete and were not: a negative for a class that does not exist,
 * closures collapsed into one caller, a vendor index thrown away by the next query, a symlinked
 * package treated as missing, and lookups that ignored what the index already knew.
 */
final class TrustworthyAnswersTest extends TestCase
{
    /** @var list<TemporaryProject> */
    private array $projects = [];

    protected function tearDown(): void
    {
        foreach ($this->projects as $project) {
            $project->destroy();
        }
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function unknownAddresses(): iterable
    {
        yield 'callers-of' => [['callers-of', 'App\Nowhere\Missing::refund']];
        yield 'references-to' => [['references-to', 'App\Nowhere\Missing']];
        yield 'reaches from' => [['reaches', 'App\Nowhere\Missing::a', 'App\Inference\Gateway::refund']];
        yield 'reaches to' => [['reaches', 'App\Inference\LegacyService::refund', 'App\Nowhere\Missing::refund']];
    }

    /**
     * @param  list<string>  $arguments
     */
    #[DataProvider('unknownAddresses')]
    public function test_an_unknown_class_is_an_error_not_an_empty_answer(array $arguments): void
    {
        $run = Cli::inLaravel($arguments);

        $this->assertSame(1, $run->exitCode);
        $this->assertStringContainsString('nothing is declared as "App\Nowhere\Missing"', $run->errors);
        $this->assertStringNotContainsString('no path', $run->output);
    }

    public function test_reaches_from_a_member_the_class_does_not_declare_is_an_error(): void
    {
        $run = Cli::inLaravel(['reaches', 'App\Inference\LegacyService::nope', 'App\Inference\Gateway']);

        $this->assertSame(1, $run->exitCode);
        $this->assertStringContainsString('declares no member "nope"', $run->errors);
    }

    public function test_a_guessed_namespace_is_corrected_and_said_so(): void
    {
        $run = Cli::inLaravel(['callers-of', 'App\Wrong\Holder::make']);

        $this->assertSame(0, $run->exitCode, $run->errors);
        $this->assertStringContainsString('callers of App\Inference\Holder::make', $run->output);
        $this->assertStringContainsString('answered App\Inference\Holder instead', $run->output);
    }

    public function test_implementors_of_a_class_says_what_kind_it_is(): void
    {
        $run = Cli::inLaravel(['implementors', 'App\Inference\Gateway']);

        $this->assertSame(1, $run->exitCode);
        $this->assertStringContainsString('is a class, not an interface', $run->errors);
        $this->assertStringContainsString('exocomp subclasses', $run->errors);
    }

    public function test_every_closure_is_its_own_caller(): void
    {
        $output = Cli::inLaravel(['callers-of', 'App\Inference\Gateway::refund', '--max-tokens', '0'])->output;

        $this->assertStringContainsString('App\Inference\ClosureCallers::{closure:first():14}', $output);
        $this->assertStringContainsString('App\Inference\ClosureCallers::{closure:second():19}', $output);
        $this->assertStringContainsString('{closure:app/Inference/helpers.php:8}', $output);
        $this->assertStringContainsString('{closure:app/Inference/helpers.php:9}', $output);
    }

    public function test_members_after_a_namespace_correction_lists_inherited_members(): void
    {
        $right = Cli::inLaravel(['members', 'App\Inference\GatewayFailed'])->output;
        $guessed = Cli::inLaravel(['members', 'App\Wrong\GatewayFailed'])->output;

        $this->assertStringContainsString('answered App\Inference\GatewayFailed instead', $guessed);
        $this->assertStringNotContainsString('--own', $guessed);
        $this->assertSame(strtok($right, "\n"), strtok($guessed, "\n"));
    }

    public function test_locate_places_a_vendor_class_by_its_full_name(): void
    {
        $run = Cli::inLaravel(['locate', 'Illuminate\Database\Eloquent\Model']);

        $this->assertSame(0, $run->exitCode, $run->errors);
        $this->assertStringContainsString('vendor/illuminate/', $run->output);
        $this->assertStringContainsString("placed through Composer's autoload map", $run->output);
    }

    public function test_a_vendor_index_survives_a_query_without_vendor(): void
    {
        $project = $this->project();
        $project->write('vendor/acme/lib/src/Thing.php', "<?php\nnamespace Acme;\nclass Thing { public function go(): void {} }\n");

        Cli::run(['index', '--warm', '--vendor', '--project', $project->root]);
        $this->assertNotSame(2, Cli::run(['callers-of', 'Fixtures\TierA\AbstractBase::describe', '--project', $project->root])->exitCode);

        $this->assertStringContainsString('Acme\Thing', Cli::run(['locate', 'Thing', '--no-sync', '--project', $project->root])->output);

        Cli::run(['index', '--no-vendor', '--project', $project->root]);

        $this->assertSame(1, Cli::run(['locate', 'Thing', '--no-sync', '--project', $project->root])->exitCode);
    }

    public function test_a_symlinked_vendor_package_is_indexed_under_its_vendor_path(): void
    {
        $project = $this->project();
        $outside = $this->project();
        $outside->write('src/Linked.php', "<?php\nnamespace Acme;\nclass Linked { public function go(): void {} }\n");
        mkdir($project->path('vendor/acme'), 0o777, true);
        symlink($outside->path('src'), $project->path('vendor/acme/linked'));

        Cli::run(['index', '--warm', '--vendor', '--project', $project->root]);

        $this->assertStringContainsString('vendor/acme/linked/Linked.php', Cli::run(['locate', 'Linked', '--no-sync', '--project', $project->root])->output);
    }

    public function test_calls_from_a_class_written_since_the_last_index(): void
    {
        $project = $this->project();
        Cli::run(['index', '--warm', '--project', $project->root]);
        $project->write('src/Fresh/Runner.php', "<?php\nnamespace Fixtures\\Fresh;\nclass Gateway { public function refund(): void {} }\nclass Runner { public function __construct(private Gateway \$g) {} public function a(): void { \$this->g->refund(); } }\n");

        $output = Cli::run(['calls-from', 'Fixtures\Fresh\Runner::a', '--project', $project->root])->output;

        $this->assertStringContainsString('Fixtures\Fresh\Gateway::refund', $output);
    }

    public function test_show_finds_a_class_that_shares_a_file_through_the_index(): void
    {
        $project = $this->project();
        $project->write('src/Shared/First.php', "<?php\nnamespace Fixtures\\Shared;\nclass First {}\nclass Second { public function go(): void {} }\n");
        Cli::run(['index', '--warm', '--project', $project->root]);

        $run = Cli::run(['show', 'Fixtures\Shared\Second::go', '--project', $project->root]);

        $this->assertSame(0, $run->exitCode, $run->errors);
        $this->assertStringContainsString('src/Shared/First.php', $run->output);
    }

    private function project(): TemporaryProject
    {
        return $this->projects[] = TemporaryProject::copyOfCorpus();
    }
}

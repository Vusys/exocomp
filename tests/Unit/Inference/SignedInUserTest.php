<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Inference;

use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Framework\Laravel\AuthModel;
use Vusys\Exocomp\Index\Index;
use Vusys\Exocomp\Inference\Reference;
use Vusys\Exocomp\Inference\ReferenceFinder;
use Vusys\Exocomp\Project\AutoloadMap;
use Vusys\Exocomp\Project\Project;
use Vusys\Exocomp\Symbol\Confidence;
use Vusys\Exocomp\Symbol\SymbolRef;
use Vusys\Exocomp\Tests\Support\Cli;

/**
 * `auth()->user()` is declared as a contract; the app's config/auth.php says which model it is.
 */
final class SignedInUserTest extends TestCase
{
    public function test_every_spelling_of_the_signed_in_user_is_the_configured_model(): void
    {
        $project = Project::at(Cli::laravelRoot());
        $index = Index::open($project, AutoloadMap::forProject($project));
        $index->sync();

        $found = [];

        foreach (new ReferenceFinder($project, $index)->find(SymbolRef::parse('App\Models\Customer::orders')) as $reference) {
            if (str_starts_with($reference->context, 'App\Services\SignedInCallers::')) {
                $found[$reference->context] = $reference;
            }
        }

        foreach (['helper', 'facade', 'request'] as $method) {
            $reference = $found['App\Services\SignedInCallers::'.$method] ?? null;

            $this->assertInstanceOf(Reference::class, $reference, $method);
            $this->assertSame(Confidence::Inferred, $reference->confidence, $method);
        }
    }

    public function test_the_model_is_read_from_a_class_constant_or_an_env_default(): void
    {
        $this->assertSame(['App\Models\Customer', 'the user provider model in config/auth.php'], AuthModel::forProject(Project::at(Cli::laravelRoot())));
        $this->assertSame('App\Models\Admin', AuthModel::forProject($this->projectWith("'model' => App\\Models\\Admin::class"))[0]);
    }

    public function test_there_is_no_answer_where_providers_disagree_or_none_is_readable(): void
    {
        $this->assertNull(AuthModel::forProject($this->projectWith("'model' => App\\Models\\Admin::class], 'b' => ['model' => App\\Models\\User::class"))[0]);
        $this->assertNull(AuthModel::forProject($this->projectWith("'model' => config('x')"))[0]);
        $this->assertNull(AuthModel::forProject($this->projectWith("'driver' => 'database'"))[0]);
    }

    private function projectWith(string $provider): Project
    {
        $root = sys_get_temp_dir().'/exocomp-auth-'.bin2hex(random_bytes(4));
        mkdir($root.'/config', recursive: true);
        file_put_contents($root.'/config/auth.php', "<?php\n\nreturn ['providers' => ['a' => [{$provider}]]];\n");

        return Project::at($root);
    }
}

<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Inference;

use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Index\Index;
use Vusys\Exocomp\Inference\Reference;
use Vusys\Exocomp\Inference\ReferenceFinder;
use Vusys\Exocomp\Project\AutoloadMap;
use Vusys\Exocomp\Project\Project;
use Vusys\Exocomp\Project\SymbolLocator;
use Vusys\Exocomp\Symbol\Confidence;
use Vusys\Exocomp\Symbol\SymbolRef;
use Vusys\Exocomp\Tests\Support\Cli;

/**
 * The global helpers installed packages declare (#13). `request()->user()` is the commonest
 * call in a Laravel controller, and it is not a call on any model's `user` relation; typing
 * `request()` from the framework's own declaration is what says so.
 */
final class InstalledFunctionsTest extends TestCase
{
    /** @var array<string, Reference> */
    private static array $byMethod = [];

    public static function setUpBeforeClass(): void
    {
        $project = Project::at(Cli::laravelRoot());
        $map = AutoloadMap::forProject($project);
        $index = Index::open($project, $map);
        $index->sync();

        // With a locator, as the commands build it, so a class the index does not hold is readable.
        foreach (new ReferenceFinder($project, $index, locator: new SymbolLocator($project, $map))->find(SymbolRef::parse('App\Models\Invoice::user')) as $reference) {
            if (str_starts_with($reference->context, 'App\Services\InvoiceChains::')) {
                self::$byMethod[substr($reference->context, strlen('App\Services\InvoiceChains::'))] = $reference;
            }

            if (str_starts_with($reference->context, 'App\Http\Controllers\SessionController::')) {
                self::$byMethod['session:'.substr($reference->context, strlen('App\Http\Controllers\SessionController::'))] = $reference;
            }
        }
    }

    public function test_a_method_declared_in_a_framework_trait_is_typed_from_its_declaration(): void
    {
        // `guard()` lives in a vendor trait and says it returns the request, so `->user()` on
        // it is the request's, not the model's.
        $this->assertArrayNotHasKey('session:check', self::$byMethod);
    }

    public function test_a_helper_typed_by_its_conditional_return_is_not_a_call_on_the_model(): void
    {
        $this->assertArrayNotHasKey('requestUser', self::$byMethod);
    }

    public function test_a_helper_with_a_native_return_type_is_not_a_call_on_the_model(): void
    {
        $this->assertArrayNotHasKey('helperDeclared', self::$byMethod);
    }

    public function test_the_other_branch_of_the_condition_stays_unresolved_and_says_why(): void
    {
        $reference = self::$byMethod['requestInput'] ?? null;

        $this->assertInstanceOf(Reference::class, $reference);
        $this->assertSame(Confidence::Textual, $reference->confidence);
        $this->assertStringContainsString('request(), in an installed package, does not return a class for this call', $reference->reason);
    }
}

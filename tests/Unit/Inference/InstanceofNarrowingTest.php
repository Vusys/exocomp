<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Inference;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Index\Index;
use Vusys\Exocomp\Inference\Reference;
use Vusys\Exocomp\Inference\ReferenceFinder;
use Vusys\Exocomp\Project\AutoloadMap;
use Vusys\Exocomp\Project\Project;
use Vusys\Exocomp\Symbol\Confidence;
use Vusys\Exocomp\Symbol\SymbolRef;
use Vusys\Exocomp\Tests\Support\Cli;

/**
 * `instanceof` is a fact PHP guarantees, so what it proves is `resolved`, and only where it
 * proves it: inside the block and the rest of the condition, after a guard that leaves, and
 * after an `assert()`.
 */
final class InstanceofNarrowingTest extends TestCase
{
    /** @var array<string, Reference> */
    private static array $byMethod = [];

    public static function setUpBeforeClass(): void
    {
        $project = Project::at(Cli::laravelRoot());
        $index = Index::open($project, AutoloadMap::forProject($project));
        $index->sync();

        foreach (new ReferenceFinder($project, $index)->find(SymbolRef::parse('App\Models\Invoice::send')) as $reference) {
            if (str_starts_with($reference->context, 'App\Services\NarrowedCallers::')) {
                self::$byMethod[substr($reference->context, strlen('App\Services\NarrowedCallers::'))] = $reference;
            }
        }
    }

    /**
     * @return iterable<string, array{string, Confidence}>
     */
    public static function callers(): iterable
    {
        yield 'inside the block' => ['insideTheBlock', Confidence::Resolved];
        yield 'in the rest of the condition' => ['inTheRestOfTheCondition', Confidence::Resolved];
        yield 'in the first branch of a ternary' => ['inTheFirstBranchOfATernary', Confidence::Resolved];
        yield 'not before the test' => ['notBeforeTheTest', Confidence::Textual];
        yield 'after a guard that throws' => ['afterAGuardThatThrows', Confidence::Resolved];
        yield 'after a guard of two that returns' => ['afterAGuardThatReturns', Confidence::Resolved];
        yield 'after an assertion' => ['afterAnAssertion', Confidence::Resolved];
        yield 'not in the else' => ['notInTheElse', Confidence::Textual];
        yield 'not after the block' => ['notAfterTheBlock', Confidence::Textual];
        yield 'not after a guard that falls through' => ['notAfterAGuardThatFallsThrough', Confidence::Textual];
        yield 'not after an either-or guard' => ['notAfterAnEitherOrGuard', Confidence::Textual];
    }

    #[DataProvider('callers')]
    public function test_instanceof_types_a_receiver_only_where_it_proves_it(string $method, Confidence $expected): void
    {
        $reference = self::$byMethod[$method] ?? null;

        $this->assertInstanceOf(Reference::class, $reference, $method);
        $this->assertSame($expected, $reference->confidence, $method);
    }
}

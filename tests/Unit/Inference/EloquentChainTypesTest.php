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
 * Eloquent chains typed as the model they hand back (#11). In a measured application these were
 * 79% of the receivers `references-to` could not type. Every one is `inferred`: the framework's
 * convention, not a declaration that was read.
 */
final class EloquentChainTypesTest extends TestCase
{
    /** @var array<string, Reference> */
    private static array $byMethod = [];

    /** @var list<Reference> references inside a closure, in source order */
    private static array $inClosures = [];

    public static function setUpBeforeClass(): void
    {
        $project = Project::at(Cli::laravelRoot());
        $index = Index::open($project, AutoloadMap::forProject($project));
        $index->sync();

        foreach (new ReferenceFinder($project, $index)->find(SymbolRef::parse('App\Models\Invoice::send')) as $reference) {
            if ($reference->context === 'App\Services\InvoiceChains::{closure}') {
                self::$inClosures[] = $reference;
            } elseif (str_starts_with($reference->context, 'App\Services\InvoiceChains::')) {
                self::$byMethod[substr($reference->context, strlen('App\Services\InvoiceChains::'))] = $reference;
            }
        }

        usort(self::$inClosures, static fn (Reference $a, Reference $b): int => $a->line <=> $b->line);
    }

    /** @return list<array{string}> */
    public static function typedChains(): array
    {
        return [
            ['factory'], ['factoryState'], ['factoryMany'], ['find'], ['findMany'], ['query'], ['assigned'], ['foreachAll'], ['foreachGet'], ['instance'],
            ['relationCreate'], ['relationQuery'], ['relationProperty'], ['relationForeach'], ['toOneProperty'], ['reassigned'], ['relationThroughHelper'], ['collectionIndex'],
        ];
    }

    #[DataProvider('typedChains')]
    public function test_a_chain_ending_at_the_model_is_an_inferred_reference(string $method): void
    {
        $reference = self::$byMethod[$method] ?? null;

        $this->assertInstanceOf(Reference::class, $reference, "no reference found in {$method}()");
        $this->assertSame(Confidence::Inferred, $reference->confidence, $method);
    }

    public function test_a_chain_through_static_and_self_return_types_is_resolved(): void
    {
        $reference = self::$byMethod['fluent'] ?? null;

        $this->assertInstanceOf(Reference::class, $reference);
        $this->assertSame(Confidence::Resolved, $reference->confidence);
    }

    public function test_a_variable_narrowed_by_instanceof_in_a_ternary_is_resolved(): void
    {
        $reference = self::$byMethod['narrowed'] ?? null;

        $this->assertInstanceOf(Reference::class, $reference);
        $this->assertSame(Confidence::Resolved, $reference->confidence);
    }

    public function test_a_method_keeps_its_scope_after_a_closure(): void
    {
        $reference = self::$byMethod['afterClosure'] ?? null;

        $this->assertInstanceOf(Reference::class, $reference);
        $this->assertSame(Confidence::Resolved, $reference->confidence);
    }

    public function test_a_closure_sees_what_php_gives_it_of_the_enclosing_scope(): void
    {
        // insideArrow, insideClosureUse, insideClosureWithoutUse, in that order.
        $this->assertSame(
            [Confidence::Resolved, Confidence::Resolved, Confidence::Textual],
            array_map(static fn (Reference $reference): Confidence => $reference->confidence, self::$inClosures),
        );
    }

    /** @return list<array{string}> */
    public static function untypedChains(): array
    {
        return [['escapes'], ['unnamed']];
    }

    #[DataProvider('untypedChains')]
    public function test_a_chain_with_a_way_out_stays_unresolved(string $method): void
    {
        $reference = self::$byMethod[$method] ?? null;

        $this->assertInstanceOf(Reference::class, $reference, $method);
        $this->assertSame(Confidence::Textual, $reference->confidence, $method);
    }
}

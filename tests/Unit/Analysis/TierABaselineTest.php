<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Analysis;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Analysis\Analyser;
use Vusys\Exocomp\Analysis\Declaration;
use Vusys\Exocomp\Analysis\FileAnalysis;
use Vusys\Exocomp\Source\SourceFile;
use Vusys\Exocomp\Symbol\SymbolKind;
use Vusys\Exocomp\Tests\Support\Cli;

/**
 * Tier A — the baseline that must resolve, 100%.
 *
 * These assert *our* handling, not that PHP parses: `nikic/php-parser` ships its own fixtures
 * and re-testing it would be waste. What is under test is the span, the ownership and the
 * signature we derive.
 */
final class TierABaselineTest extends TestCase
{
    public function test_it_finds_every_kind_of_declaration(): void
    {
        $baseline = $this->classLike('src/TierA/Baseline.php', 'Fixtures\TierA\Baseline');

        $this->assertSame(SymbolKind::ClassType, $baseline->kind);
        $this->assertSame(['final'], $baseline->modifiers);
        $this->assertSame(['Fixtures\TierA\AbstractBase'], $baseline->extends);
        $this->assertSame(['Fixtures\TierA\Contract'], $baseline->implements);
        $this->assertSame(['Fixtures\TierA\Loggable'], $baseline->traits);
    }

    public function test_a_span_starts_at_the_docblock_and_ends_at_the_closing_brace(): void
    {
        $greet = $this->member('src/TierA/Baseline.php', 'Fixtures\TierA\Baseline', 'greet');
        $source = $this->analyse('src/TierA/Baseline.php')->file->lines($greet->spanStartLine, $greet->endLine);

        $this->assertStringStartsWith('    /**', $source);
        $this->assertStringEndsWith('    }', $source);
        $this->assertStringContainsString('public function greet', $source);

        // The docblock precedes the declaration, so the span the reader gets is wider than
        // the node — which is the point: a method's documentation is part of the method.
        $this->assertLessThan($greet->startLine, $greet->spanStartLine);
    }

    public function test_a_scalar_default_rides_along_in_the_dense_signature(): void
    {
        // For a great many properties the value *is* the answer — a table name, a connection,
        // a lookback window. Showing the type and hiding the value would send the reader back
        // to the file for one token's worth of information.
        $label = $this->member('src/TierA/Baseline.php', 'Fixtures\TierA\Baseline', 'label', isProperty: true);

        $this->assertSame('protected string $label = self::DEFAULT_LABEL', $label->signature);
        $this->assertSame('private static int $instances = 0', $this->member('src/TierA/Baseline.php', 'Fixtures\TierA\Baseline', 'instances', isProperty: true)->signature);
    }

    public function test_a_complex_default_is_left_to_show_rather_than_flattened_onto_one_line(): void
    {
        // An array default would blow the line budget that makes `outline` worth using.
        $this->assertSame('protected array $log', $this->member('src/TierA/Loggable.php', 'Fixtures\TierA\Loggable', 'log', isProperty: true)->signature);
    }

    public function test_constructor_promoted_parameters_are_reported_as_properties(): void
    {
        $repository = $this->member('src/TierA/Baseline.php', 'Fixtures\TierA\Baseline', 'repository', isProperty: true);

        $this->assertSame(SymbolKind::Property, $repository->kind);
        $this->assertTrue($repository->isPromoted);
        $this->assertSame(['private', 'readonly'], $repository->modifiers);
        $this->assertSame('private readonly Repository $repository', $repository->signature);
    }

    public function test_a_promoted_property_and_its_constructor_are_both_listed(): void
    {
        $class = $this->classLike('src/TierA/Baseline.php', 'Fixtures\TierA\Baseline');
        $names = array_map(static fn (Declaration $m): string => $m->kind->value.' '.$m->name, $class->members);

        $this->assertContains('method __construct', $names);
        $this->assertContains('property repository', $names);
        $this->assertContains('property label', $names);
    }

    public function test_members_are_listed_in_source_order(): void
    {
        $class = $this->classLike('src/TierA/Baseline.php', 'Fixtures\TierA\Baseline');
        $lines = array_map(static fn (Declaration $m): int => $m->startLine, $class->members);
        $sorted = $lines;
        sort($sorted);

        $this->assertSame($sorted, $lines);
    }

    #[DataProvider('signatures')]
    public function test_it_renders_dense_signatures(string $member, string $expected): void
    {
        $this->assertSame($expected, $this->member('src/TierA/Types.php', 'Fixtures\TierA\Types', $member)->signature);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function signatures(): iterable
    {
        yield 'union' => ['union', 'public function union(int|string): int|false'];
        yield 'intersection' => ['intersection', 'public function intersection(Countable&Stringable): string'];
        yield 'dnf' => ['disjunctiveNormalForm', 'public function disjunctiveNormalForm((Countable&Stringable)|null): ?string'];
        yield 'nullable' => ['nullable', 'public function nullable(?Contract): ?Contract'];
        yield 'never' => ['fails', 'public function fails(string): never'];
        yield 'static' => ['self', 'public function self(): static'];
        yield 'variadic' => ['variadic', 'public function variadic(string, ...int|float): array'];
        yield 'by reference' => ['byReference', 'public function byReference(&array): void'];
    }

    public function test_it_reads_enums_including_the_backing_type_and_cases(): void
    {
        $colour = $this->classLike('src/TierA/Colour.php', 'Fixtures\TierA\Colour');

        $this->assertSame(SymbolKind::EnumType, $colour->kind);
        $this->assertSame('enum Colour: string implements Describable', $colour->signature);

        $red = $colour->member('Red', isProperty: false);
        $this->assertInstanceOf(Declaration::class, $red);
        $this->assertSame(SymbolKind::EnumCase, $red->kind);
        $this->assertSame("case Red = 'red'", $red->signature);
    }

    public function test_a_pure_enum_case_has_no_value(): void
    {
        $draft = $this->member('src/TierA/Status.php', 'Fixtures\TierA\Status', 'Draft');

        $this->assertSame('case Draft', $draft->signature);
    }

    public function test_interface_constants_and_abstract_methods_are_members(): void
    {
        $contract = $this->classLike('src/TierA/Contract.php', 'Fixtures\TierA\Contract');

        $this->assertSame(SymbolKind::InterfaceType, $contract->kind);
        $this->assertSame(['Fixtures\TierA\Describable'], $contract->extends);

        $version = $contract->member('VERSION', isProperty: false);
        $this->assertInstanceOf(Declaration::class, $version);
        $this->assertSame("public const string VERSION = '1.0'", $version->signature);

        $greet = $contract->member('greet', isProperty: false);
        $this->assertInstanceOf(Declaration::class, $greet);
        $this->assertFalse($greet->hasBody);
    }

    public function test_a_readonly_class_and_its_trait_members_are_described(): void
    {
        $this->assertSame(['final', 'readonly'], $this->classLike('src/TierA/Types.php', 'Fixtures\TierA\Types')->modifiers);
        $this->assertSame(SymbolKind::TraitType, $this->classLike('src/TierA/Loggable.php', 'Fixtures\TierA\Loggable')->kind);
    }

    private function analyse(string $relative): FileAnalysis
    {
        $path = Cli::corpusFile($relative);

        return (new Analyser)->analyse(SourceFile::fromPath($path, $relative));
    }

    private function classLike(string $relative, string $fqn): Declaration
    {
        $declaration = $this->analyse($relative)->classLike($fqn);

        $this->assertInstanceOf(Declaration::class, $declaration, "{$fqn} not found in {$relative}");

        return $declaration;
    }

    private function member(string $relative, string $fqn, string $name, bool $isProperty = false): Declaration
    {
        $member = $this->classLike($relative, $fqn)->member($name, $isProperty);

        $this->assertInstanceOf(Declaration::class, $member, "{$fqn}::{$name} not found");

        return $member;
    }
}

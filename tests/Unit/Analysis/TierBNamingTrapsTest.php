<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Analysis;

use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Analysis\Analyser;
use Vusys\Exocomp\Analysis\Declaration;
use Vusys\Exocomp\Analysis\FileAnalysis;
use Vusys\Exocomp\Analysis\Import;
use Vusys\Exocomp\Analysis\ImportKind;
use Vusys\Exocomp\Source\SourceFile;
use Vusys\Exocomp\Symbol\SymbolKind;
use Vusys\Exocomp\Tests\Support\Cli;

/**
 * Tier B — the naming and resolution traps, and the tier that justifies the tool existing.
 *
 * Every case here is one where a textual search is *wrong* rather than merely noisy: it finds
 * a name that is not the thing, or misses the thing because it is written under another name.
 */
final class TierBNamingTrapsTest extends TestCase
{
    public function test_an_aliased_import_records_both_the_real_name_and_the_local_one(): void
    {
        $store = $this->import('src/TierB/Aliases.php', 'Fixtures\TierA\Support\Repository');

        $this->assertSame('Store', $store->alias);
        $this->assertTrue($store->isAliased);
        $this->assertSame(ImportKind::ClassLike, $store->kind);
    }

    public function test_an_unaliased_import_is_not_reported_as_aliased(): void
    {
        $baseline = $this->import('src/TierB/Aliases.php', 'Fixtures\TierA\Baseline');

        $this->assertSame('Baseline', $baseline->alias);
        $this->assertFalse($baseline->isAliased);
    }

    public function test_a_group_use_expands_to_one_import_per_name(): void
    {
        $alpha = $this->import('src/TierB/Aliases.php', 'Fixtures\TierB\Deep\Alpha');
        $beta = $this->import('src/TierB/Aliases.php', 'Fixtures\TierB\Deep\Beta');

        $this->assertFalse($alpha->isAliased);
        $this->assertSame('Second', $beta->alias);
        $this->assertSame($alpha->line, $beta->line);
    }

    public function test_function_and_constant_imports_keep_their_kind(): void
    {
        $this->assertSame(ImportKind::FunctionName, $this->import('src/TierB/Aliases.php', 'Fixtures\TierB\Helpers\normalise')->kind);
        $this->assertSame(ImportKind::ConstantName, $this->import('src/TierB/Aliases.php', 'Fixtures\TierB\Helpers\MAX_DEPTH')->kind);
    }

    public function test_a_class_name_lookup_is_case_insensitive_as_php_is(): void
    {
        $analysis = $this->analyse('src/TierB/Casing.php');

        $this->assertInstanceOf(Declaration::class, $analysis->classLike('fixtures\tierb\casing'));
        $this->assertInstanceOf(Declaration::class, $analysis->classLike('FIXTURES\TIERB\CASING'));
    }

    public function test_a_method_lookup_is_case_insensitive_but_a_property_lookup_is_not(): void
    {
        $casing = $this->classLike('src/TierB/Casing.php', 'Fixtures\TierB\Casing');

        $this->assertInstanceOf(Declaration::class, $casing->member('HANDLE', isProperty: false));

        $lower = $casing->member('value', isProperty: true);
        $upper = $casing->member('Value', isProperty: true);

        $this->assertInstanceOf(Declaration::class, $lower);
        $this->assertInstanceOf(Declaration::class, $upper);

        // Two genuinely different properties. An index that lowercased both would answer one
        // question with the other's line number — confidently, and wrongly.
        $this->assertNotSame($lower->startLine, $upper->startLine);
    }

    public function test_a_constant_lookup_is_case_sensitive(): void
    {
        $casing = $this->classLike('src/TierB/Casing.php', 'Fixtures\TierB\Casing');

        $upper = $casing->member('VALUE', isProperty: false);
        $this->assertInstanceOf(Declaration::class, $upper);
        $this->assertSame(SymbolKind::Constant, $upper->kind);

        // `value` must not case-fold onto the `VALUE` constant. It falls through to the
        // same-named property instead, which is a different member with a different line.
        $lower = $casing->member('value', isProperty: false);
        $this->assertInstanceOf(Declaration::class, $lower);
        $this->assertSame(SymbolKind::Property, $lower->kind);
    }

    public function test_several_classes_in_one_file_are_all_found(): void
    {
        $analysis = $this->analyse('src/TierB/Collisions.php');

        $this->assertCount(2, $analysis->declarations);
        $this->assertInstanceOf(Declaration::class, $analysis->classLike('Fixtures\TierB\Alpha'));
        $this->assertInstanceOf(Declaration::class, $analysis->classLike('Fixtures\TierB\Omega'));
    }

    public function test_a_short_name_shared_across_namespaces_stays_distinct(): void
    {
        $collision = $this->classLike('src/TierB/Collisions.php', 'Fixtures\TierB\Alpha');
        $deep = $this->classLike('src/TierB/Deep/Alpha.php', 'Fixtures\TierB\Deep\Alpha');

        $this->assertSame('Alpha', $collision->name);
        $this->assertSame('Alpha', $deep->name);
        $this->assertNotSame($collision->fqn, $deep->fqn);
    }

    public function test_brace_syntax_namespaces_are_both_walked(): void
    {
        $analysis = $this->analyse('src/TierB/Braces.php');

        $this->assertSame(['Fixtures\TierB\First', 'Fixtures\TierB\Second'], $analysis->namespaces);
        $this->assertInstanceOf(Declaration::class, $analysis->classLike('Fixtures\TierB\First\Inhabitant'));
        $this->assertInstanceOf(Declaration::class, $analysis->classLike('Fixtures\TierB\Second\Inhabitant'));
    }

    public function test_a_conditionally_declared_class_is_found(): void
    {
        $analysis = $this->analyse('src/TierB/Conditional.php');

        $this->assertInstanceOf(Declaration::class, $analysis->classLike('Fixtures\TierB\Polyfill'));
    }

    public function test_relative_and_imported_names_resolve_to_the_same_fully_qualified_form(): void
    {
        $relative = $this->classLike('src/TierB/Relative.php', 'Fixtures\TierB\Relative');

        // Written `Deep\Alpha` and `Contract` respectively. Neither string appears in the
        // resolved answer, which is exactly why grep cannot produce it.
        $this->assertSame(['Fixtures\TierB\Deep\Alpha'], $relative->extends);
        $this->assertSame(['Fixtures\TierA\Contract'], $relative->implements);
    }

    public function test_class_names_fold_by_ascii_rules_only(): void
    {
        $analysis = $this->analyse('src/TierB/Accents.php');

        // Two classes, not one. PHP leaves every byte above 0x7F alone when it folds a name,
        // so this accented pair stays distinct where an ASCII pair would be the same class.
        $this->assertCount(2, $analysis->declarations);

        $upper = $this->classLike('src/TierB/Accents.php', "Fixtures\\TierB\\\u{00C9}tage");
        $lower = $this->classLike('src/TierB/Accents.php', "Fixtures\\TierB\\\u{00E9}tage");

        $this->assertNotSame($upper->startLine, $lower->startLine);
    }

    private function analyse(string $relative): FileAnalysis
    {
        return (new Analyser)->analyse(SourceFile::fromPath(Cli::corpusFile($relative), $relative));
    }

    private function classLike(string $relative, string $fqn): Declaration
    {
        $declaration = $this->analyse($relative)->classLike($fqn);

        $this->assertInstanceOf(Declaration::class, $declaration, "{$fqn} not found in {$relative}");

        return $declaration;
    }

    private function import(string $relative, string $fqn): Import
    {
        foreach ($this->analyse($relative)->imports as $import) {
            if ($import->fqn === $fqn) {
                return $import;
            }
        }

        $this->fail("no import of {$fqn} in {$relative}");
    }
}

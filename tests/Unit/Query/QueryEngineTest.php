<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Query;

use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Query\Pattern;
use Vusys\Exocomp\Query\Predicates;
use Vusys\Exocomp\Query\QueryEngine;
use Vusys\Exocomp\Query\QueryMatch;
use Vusys\Exocomp\Source\SourceFile;
use Vusys\Exocomp\Tests\Support\Cli;

/**
 * Matching, and the predicates that narrow it.
 *
 * The archetype throughout is the one from the audit: a call site where an argument is *not* a
 * literal. Written as text that took four chained filters and left a tail of false positives to
 * eyeball; the point of the tests is that the structural form has neither.
 */
final class QueryEngineTest extends TestCase
{
    public function test_a_call_pattern_matches_every_call_site(): void
    {
        $this->assertCount(4, $this->matchesFor('$X->record(...)'));
    }

    public function test_a_literal_argument_predicate_splits_them(): void
    {
        $literal = $this->matchesFor('$X->record(...)', new Predicates(literalArguments: [0]));
        $computed = $this->matchesFor('$X->record(...)', new Predicates(nonLiteralArguments: [0]));

        $this->assertCount(2, $literal);
        $this->assertCount(2, $computed);

        // Every match falls on exactly one side, which is what a text filter could not promise.
        $this->assertSame(4, count($literal) + count($computed));
    }

    public function test_a_concatenation_is_not_a_literal(): void
    {
        $computed = $this->matchesFor('$X->record(...)', new Predicates(nonLiteralArguments: [0]));
        $sources = array_map(static fn (QueryMatch $m): string => $m->snippet(), $computed);

        $this->assertContains("\$this->record(\$key.'.suffix', \$count)", $sources);
    }

    public function test_an_interpolated_string_is_not_a_literal(): void
    {
        $file = SourceFile::fromString(<<<'PHP'
            <?php
            config("services.{$name}.timeout");
            config("services.$name");
            config(<<<EOT
                services.{$name}
                EOT);
            config(["services.{$name}"]);
            config("services.timeout");
            config(['services.timeout']);
            PHP, 'calls.php');

        $snippets = static fn (Predicates $predicates): array => array_map(
            static fn (QueryMatch $m): string => $m->snippet(),
            new QueryEngine($predicates)->runOn(Pattern::parse('config(...)'), $file),
        );

        $this->assertCount(4, $snippets(new Predicates(nonLiteralArguments: [0])));
        $this->assertSame(['config("services.timeout")', "config(['services.timeout'])"], $snippets(new Predicates(literalArguments: [0])));
    }

    public function test_a_constant_expression_counts_as_a_literal(): void
    {
        $file = SourceFile::fromString(<<<'PHP_WRAP'
        <?php
        query('a = ' . '1');
        query(self::SQL);
        query(Status::Paid->value);
        query(['a' => self::SQL, 'b' => 1 + 2]);
        query(PHP_EOL);
        query(APP_DEFINED);
        query($runtime . ' = 1');
        PHP_WRAP, 'calls.php');

        $snippets = static fn (Predicates $predicates): array => array_map(
            static fn (QueryMatch $m): string => $m->snippet(),
            new QueryEngine($predicates)->runOn(Pattern::parse('query(...)'), $file),
        );

        // A constant `define()`d by the application can be set from anything, so it is not one.
        $this->assertSame(['query(APP_DEFINED)', "query(\$runtime . ' = 1')"], $snippets(new Predicates(nonLiteralArguments: [0])));
        $this->assertCount(5, $snippets(new Predicates(literalArguments: [0])));
    }

    public function test_captures_bind_and_carry_their_source(): void
    {
        $matches = $this->matchesFor('$this->repository->_M($A)');
        $names = array_map(
            static fn (QueryMatch $m): string => $m->captures->text($m->file)['M'] ?? '',
            $matches,
        );

        $this->assertSame(['find', 'findByUuid', 'findByUuid'], $names);
    }

    public function test_an_annotation_predicate_narrows_to_the_enclosing_declaration(): void
    {
        $matches = $this->matchesFor('$R->findByUuid($U)', new Predicates(annotated: ['@deprecated']));

        $this->assertCount(1, $matches);
        $this->assertSame('Fixtures\TierB\CallSites::oldLookup', $matches[0]->context());
    }

    public function test_a_namespace_predicate_narrows_by_where_the_match_sits(): void
    {
        $this->assertCount(4, $this->matchesFor('$X->record(...)', new Predicates(in: ['Fixtures\TierB\**'])));
        $this->assertCount(0, $this->matchesFor('$X->record(...)', new Predicates(in: ['Fixtures\TierA\**'])));
        $this->assertCount(0, $this->matchesFor('$X->record(...)', new Predicates(notIn: ['Fixtures\TierB\**'])));
    }

    public function test_visibility_and_return_type_narrow_a_declaration_pattern(): void
    {
        $protectedVoid = $this->matchesFor('function _M() {}', new Predicates(visibility: 'protected', returns: 'void'));

        $this->assertCount(1, $protectedVoid);
        $this->assertSame('record', $protectedVoid[0]->captures->text($protectedVoid[0]->file)['M']);
    }

    public function test_a_parameter_count_predicate_is_exact(): void
    {
        $this->assertCount(0, $this->matchesFor('function _M() {}', new Predicates(paramCount: 9)));
        $this->assertNotCount(0, $this->matchesFor('function _M() {}', new Predicates(paramCount: 2)));
    }

    /**
     * The matcher must be tight by default. A pattern naming a method matches that method and
     * nothing else — a loose matcher would produce confident false positives, which is worse
     * than grep rather than better.
     */
    public function test_a_named_call_does_not_match_a_differently_named_one(): void
    {
        $this->assertCount(0, $this->matchesFor('$X->recordSomethingElse(...)'));
    }

    public function test_an_argument_count_must_agree_unless_the_pattern_says_otherwise(): void
    {
        $this->assertCount(4, $this->matchesFor('$X->record($A, $B)'));
        $this->assertCount(0, $this->matchesFor('$X->record($A)'));
    }

    public function test_an_unparseable_file_yields_nothing_rather_than_failing(): void
    {
        $engine = new QueryEngine;
        $file = SourceFile::fromPath(Cli::corpusFile('src/TierF/Unparseable.php'), 'broken.php');

        $this->assertSame([], $engine->runOn(Pattern::parse('$X->record(...)'), $file));
    }

    /**
     * Narrowing must never lose a match.
     *
     * The index rules out files by the names they mention, which is what turns a corpus-wide
     * query from twelve seconds into a fraction of one. It is only sound if the names a pattern
     * requires really are required — an over-eager filter would silently shrink the answer,
     * which on an audit is the worst possible failure.
     */
    public function test_required_names_are_only_the_ones_written_literally(): void
    {
        $this->assertSame(['record'], Pattern::parse('$X->record($A, $B)')->requiredNames());
        $this->assertSame(['repository'], Pattern::parse('$this->repository->_M($A)')->requiredNames());
        $this->assertSame(['findByUuid'], Pattern::parse('$R->findByUuid($U)')->requiredNames());

        // Nothing literal to filter on, so nothing is filtered out and the sweep is full.
        $this->assertSame([], Pattern::parse('function _M() {}')->requiredNames());
        $this->assertSame([], Pattern::parse('$X->_M($A)')->requiredNames());
    }

    /**
     * @return list<QueryMatch>
     */
    private function matchesFor(string $pattern, ?Predicates $predicates = null): array
    {
        $engine = new QueryEngine($predicates ?? new Predicates);
        $file = SourceFile::fromPath(Cli::corpusFile('src/TierB/CallSites.php'), 'src/TierB/CallSites.php');

        return $engine->runOn(Pattern::parse($pattern), $file);
    }
}

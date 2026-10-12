<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Inference;

use PhpParser\NodeTraverser;
use Vusys\Exocomp\Analysis\Analyser;
use Vusys\Exocomp\Index\Index;
use Vusys\Exocomp\Index\SymbolGraph;
use Vusys\Exocomp\Index\SymbolRecord;
use Vusys\Exocomp\Project\ClassSource;
use Vusys\Exocomp\Project\Project;
use Vusys\Exocomp\Project\SymbolLocator;
use Vusys\Exocomp\Source\ParseFailure;
use Vusys\Exocomp\Source\SourceFile;
use Vusys\Exocomp\Symbol\Confidence;
use Vusys\Exocomp\Symbol\SymbolKind;
use Vusys\Exocomp\Symbol\SymbolRef;

/**
 * Every reference to a symbol across the corpus.
 *
 * Candidate files come from the index's record of what each file *mentions*, which is what
 * makes this affordable: a name is referred to in a handful of files, not in six and a half
 * thousand, and only the handful is parsed.
 *
 * That narrowing is also the one place recall could silently be lost, so it is deliberately
 * coarse — the short name alone, case-folded. Anything that could refer to the symbol has to
 * write its name somewhere, except through the dynamic constructs this engine reports as
 * unresolved anyway.
 */
final class ReferenceFinder
{
    /** @var list<string> */
    private array $notes = [];

    private int $unsearched = 0;

    private bool $undeclared = false;

    public function __construct(
        private readonly Project $project,
        private readonly Index $index,
        private readonly Analyser $analyser = new Analyser,
        private readonly ?SymbolLocator $locator = null,
    ) {}

    /**
     * @param  float|null  $deadline  a `microtime(true)` past which no further file is opened; what
     *                                was found is returned, and {@see self::unsearched()} says how
     *                                much was not looked at
     * @param  (callable(int $searched, int $of): void)|null  $progress
     * @return list<Reference>
     */
    public function find(SymbolRef $target, bool $includeStrings = false, ?float $deadline = null, ?callable $progress = null): array
    {
        $graph = $this->index->graph();
        $magic = Magic::forProject($graph, new ClassSource($this->project, $graph, $this->locator), $this->project);
        $shortName = $this->shortName($target->hasMember() ? (string) $target->member : $target->owner);
        $types = new TypeResolver($graph, $magic);

        // A member the framework lets you call by another name is mentioned by that other name
        // and no other, so the narrowing has to look for both. Getting this wrong would not
        // produce an error — it would produce an empty answer with full confidence in it, which
        // on a rename is how a codebase breaks at runtime.
        $spellings = $target->hasMember() ? $magic->spellings($target->owner, $shortName) : [];
        $undeclared = $this->undeclared($target, $graph, $magic);

        if ($undeclared !== null) {
            $this->notes[] = $undeclared;
            $this->undeclared = true;
        }

        $references = [];

        $candidates = $this->candidates($shortName, $spellings, $this->shortName($target->owner));

        foreach ($candidates as $searched => $path) {
            if ($deadline !== null && microtime(true) > $deadline) {
                $this->unsearched = count($candidates) - $searched;

                break;
            }

            if ($progress !== null) {
                $progress($searched, count($candidates));
            }

            $absolute = $this->project->path($path);

            if (! is_file($absolute)) {
                continue;
            }

            try {
                [$analysis, $statements] = $this->analyser->analyseWithAst(SourceFile::fromPath($absolute, $path));
            } catch (ParseFailure $failure) {
                // A file that will not parse cannot be searched, and saying so matters: "no
                // references" and "could not look" are different answers, and on a rename the
                // difference is a broken codebase.
                $this->notes[] = sprintf('%s:%d could not be parsed, so it was not searched', $failure->path, $failure->startLine);

                continue;
            }

            $scanner = new ReferenceScanner($target, $analysis, $graph, $types, $magic, $spellings, $undeclared === null, 'no declaration of this name');
            new NodeTraverser($scanner)->traverse($statements);

            $references = [...$references, ...$scanner->references()];
        }

        if ($includeStrings) {
            $references = [...$references, ...$this->stringReferences($target)];
        }

        usort($references, static fn (Reference $a, Reference $b): int => [$a->path, $a->line, $a->column]
            <=> [$b->path, $b->line, $b->column]);

        return $references;
    }

    /**
     * A class name written as a string is invisible to name resolution and to the index, and it
     * is the hazard that makes a rename dangerous: config arrays, container bindings keyed by
     * string, and `morphTo` type columns all hold them. Finding them means reading files the
     * index would not have suggested, so it is opt-in — and everything found this way is
     * `textual`, because a matching string is not evidence that it means the class.
     *
     * @return list<Reference>
     */
    private function stringReferences(SymbolRef $target): array
    {
        $needle = $target->hasMember() ? (string) $target->member : $target->owner;
        $found = [];

        foreach ($this->index->store->paths($this->project->root) as $path) {
            $absolute = $this->project->path($path);
            $contents = is_file($absolute) ? @file_get_contents($absolute) : false;

            if ($contents === false) {
                continue;
            }

            foreach ($this->literalOccurrences($contents, $needle) as [$line, $column, $snippet]) {
                $found[] = new Reference(
                    path: $path,
                    line: $line,
                    column: $column,
                    kind: 'string',
                    confidence: Confidence::Textual,
                    reason: 'the name appears as a string literal',
                    snippet: $snippet,
                    context: '(string literal)',
                );
            }
        }

        return $found;
    }

    /**
     * @return list<array{int, int, string}>
     */
    private function literalOccurrences(string $contents, string $needle): array
    {
        // Both spellings a PHP string can use for a namespace separator.
        $candidates = array_unique([$needle, str_replace('\\', '\\\\', $needle)]);
        $found = [];

        foreach ($candidates as $candidate) {
            $offset = 0;

            while (($position = strpos($contents, $candidate, $offset)) !== false) {
                $offset = $position + 1;
                $before = substr($contents, 0, $position);
                $line = substr_count($before, "\n") + 1;
                $lineStart = (int) strrpos("\n".$before, "\n");
                $text = trim((string) strtok(mb_strcut($contents, $position - min($position, 40), null, 'UTF-8'), "\n"));

                // Cut on a character boundary. This runs over arbitrary source, so a byte cut
                // would split a multi-byte character and produce a record that is not valid
                // UTF-8 — the same fault this tool's own `check` rule exists to catch.
                $snippet = strlen($text) > 90 ? mb_strcut($text, 0, 90, 'UTF-8').'…' : $text;

                $found[] = [$line, $position - $lineStart + 1, $snippet];
            }
        }

        return $found;
    }

    /**
     * @return list<string>
     */
    public function notes(): array
    {
        return $this->notes;
    }

    /**
     * Whether the member searched for is provably declared nowhere in the class's ancestry —
     * which makes every match a name rather than a reference to anything that was asked about.
     */
    public function searchedForNothing(): bool
    {
        return $this->undeclared;
    }

    /**
     * How many candidate files were never opened because the deadline passed first. Anything
     * other than zero means the answer is a lower bound, and must be reported as one.
     */
    public function unsearched(): int
    {
        return $this->unsearched;
    }

    /**
     * Whether the member being searched for is declared at all.
     *
     * Asked once, about the target, rather than at every call site — it is a property of the
     * question, not of the answers. A name that no declaration and no magic accounts for still
     * matches text, and those matches are worth showing; calling them references to a
     * declaration that is not there is not.
     *
     * Only a class read to the end of its ancestry can prove this. Anything whose parents leave
     * the corpus — which is most of a framework application — has an API larger than anything
     * readable, and an absence there says nothing.
     */
    private function undeclared(SymbolRef $target, SymbolGraph $graph, Magic $magic): ?string
    {
        if (! $target->hasMember()) {
            return null;
        }

        $owner = $target->owner;
        $member = (string) $target->member;
        $members = new Members($graph);

        foreach ([SymbolKind::Method, SymbolKind::Property, SymbolKind::Constant, SymbolKind::EnumCase] as $kind) {
            if ($members->declared($owner, $member, $kind) instanceof SymbolRecord) {
                return null;
            }
        }

        if (! $members->isComplete($owner)) {
            return null;
        }

        if ($magic->method($owner, $member) instanceof MagicMember || $magic->property($owner, $member) instanceof MagicMember) {
            return null;
        }

        return sprintf('%s declares no %s, and its ancestry is readable to the end — so every match is a name, not a reference to a declaration', $owner, $member);
    }

    /**
     * The files worth opening.
     *
     * The primary name narrows on its own: anything that could refer to the symbol has to write
     * its name somewhere. A second spelling cannot narrow on its own — `active` appears in 719
     * files of one measured application against 9 for `scopeActive` — so it is paired with the
     * owner's short name, which costs nothing in recall: a call site whose receiver *resolves*
     * to a class had that class's name written in the same file, in an import, a type hint, a
     * docblock or the `extends` clause. That is the same argument the primary narrowing already
     * rests on, applied to the other half of the pair.
     *
     * Without the pairing this search took 30 seconds where it takes two.
     *
     * @param  list<string>  $spellings
     * @return list<string>
     */
    private function candidates(string $primary, array $spellings, string $owner): array
    {
        $paths = [];

        foreach ([[$primary], ...array_map(static fn (string $spelling): array => [$spelling, $owner], $spellings)] as $names) {
            foreach ($this->index->store->pathsMentioning($this->project->root, $names) as $path) {
                $paths[$path] = true;
            }
        }

        $found = array_keys($paths);
        sort($found);

        return $found;
    }

    private function shortName(string $fqn): string
    {
        $position = strrpos($fqn, '\\');

        return $position === false ? $fqn : substr($fqn, $position + 1);
    }
}

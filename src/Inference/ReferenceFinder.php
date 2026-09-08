<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Inference;

use PhpParser\NodeTraverser;
use Vusys\Exocomp\Analysis\Analyser;
use Vusys\Exocomp\Index\Index;
use Vusys\Exocomp\Project\Project;
use Vusys\Exocomp\Source\ParseFailure;
use Vusys\Exocomp\Source\SourceFile;
use Vusys\Exocomp\Symbol\Confidence;
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

    public function __construct(
        private readonly Project $project,
        private readonly Index $index,
        private readonly Analyser $analyser = new Analyser,
    ) {}

    /**
     * @return list<Reference>
     */
    public function find(SymbolRef $target, bool $includeStrings = false): array
    {
        $graph = $this->index->graph();
        $types = new TypeResolver($graph);
        $shortName = $this->shortName($target->hasMember() ? (string) $target->member : $target->owner);

        $references = [];

        foreach ($this->index->store->pathsMentioning($this->project->root, [$shortName]) as $path) {
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

            $scanner = new ReferenceScanner($target, $analysis, $graph, $types);
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

    private function shortName(string $fqn): string
    {
        $position = strrpos($fqn, '\\');

        return $position === false ? $fqn : substr($fqn, $position + 1);
    }
}

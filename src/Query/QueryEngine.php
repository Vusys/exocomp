<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Query;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use Vusys\Exocomp\Analysis\Analyser;
use Vusys\Exocomp\Index\SymbolGraph;
use Vusys\Exocomp\Inference\TypeResolver;
use Vusys\Exocomp\Source\SourceFile;

/**
 * Runs a pattern over a corpus.
 *
 * The walk tracks what encloses each node — namespace, class, function — because most of the
 * value is in the predicates, and the predicates are almost all about context. Reconstructing
 * that afterwards from line numbers is what the piped-grep approach had to do, and it is where
 * its false positives came from.
 */
final readonly class QueryEngine
{
    private Parser $parser;

    public function __construct(
        private Predicates $predicates = new Predicates,
        private ?SymbolGraph $graph = null,
    ) {
        $this->parser = (new ParserFactory)->createForNewestSupportedVersion();
    }

    /**
     * @param  iterable<SourceFile>  $files
     * @return iterable<QueryMatch>
     */
    public function run(Pattern $pattern, iterable $files): iterable
    {
        foreach ($files as $file) {
            yield from $this->runOn($pattern, $file);
        }
    }

    /**
     * @return list<QueryMatch>
     */
    public function runOn(Pattern $pattern, SourceFile $file): array
    {
        try {
            $statements = $this->parser->parse($file->contents);
        } catch (Error) {
            // One unparseable file degrades that file. A corpus-wide query that aborted on the
            // first half-edited file would be useless exactly when it is most wanted.
            return [];
        }

        if ($statements === null) {
            return [];
        }

        // Receiver predicates need a hierarchy to judge against, and the analysis for the
        // file's imports. Neither is built when nothing asks for them.
        $needsTypes = $this->predicates->needsReceiver() && $this->graph instanceof SymbolGraph;
        $analysis = null;
        $types = null;

        if ($needsTypes) {
            [$analysis] = new Analyser()->analyseWithAst($file);
            $types = new TypeResolver($this->graph);
        }

        $collector = new MatchCollector($pattern, $file, $this->predicates, $types, $analysis, $this->graph);

        new NodeTraverser(new NodeVisitor\NameResolver(null, ['replaceNodes' => false]), $collector)->traverse($statements);

        return $collector->matches();
    }
}

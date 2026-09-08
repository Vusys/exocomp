<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Analysis;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use Vusys\Exocomp\Source\ParseFailure;
use Vusys\Exocomp\Source\SourceFile;

/**
 * Parses one file and extracts its declarations.
 *
 * The parser is created for the newest supported PHP version rather than a pinned one. That is
 * a correctness commitment, not a default: the reason for owning this engine at all is that
 * the obvious alternative could not see PHP 8.4 syntax, and a code-intelligence tool that
 * mis-parses a property hook is exactly the confidently-wrong failure the design rules out.
 *
 * Names are resolved with `replaceNodes: false` so nodes keep the spelling the file used —
 * `show` must be able to print the original bytes — while `extends`/`implements`/`use` still
 * resolve to fully-qualified names via the `resolvedName` attribute.
 */
final readonly class Analyser
{
    private Parser $parser;

    private NameResolver $nameResolver;

    public function __construct()
    {
        $this->parser = (new ParserFactory)->createForNewestSupportedVersion();
        $this->nameResolver = new NameResolver(null, ['replaceNodes' => false]);
    }

    public function analyse(SourceFile $file): FileAnalysis
    {
        try {
            $statements = $this->parser->parse($file->contents);
        } catch (Error $error) {
            throw new ParseFailure($file->displayPath, $error->getStartLine(), $error->getRawMessage());
        }

        if ($statements === null) {
            throw new ParseFailure($file->displayPath, 1, 'the parser produced no statements');
        }

        // Both visitors ride the one traversal: names must be resolved before anything can be
        // read off the tree, and anonymous classes are found on the same walk rather than a
        // second one.
        $anonymous = new AnonymousClassCollector($file);
        $statements = new NodeTraverser($this->nameResolver, $anonymous)->traverse($statements);

        $collector = new DeclarationCollector($file);
        $collector->collect($statements);

        return new FileAnalysis(
            $file,
            $collector->declarations(),
            $collector->imports(),
            $collector->namespaces(),
            $anonymous->found(),
        );
    }

    /**
     * Resolves the fully-qualified name a `Name` node refers to, preferring what the name
     * resolver worked out over the literal spelling. A relative or imported short name means
     * nothing on its own — resolving it is precisely the thing grep cannot do.
     */
    public static function resolvedName(Node\Name $name): string
    {
        $resolved = $name->getAttribute('resolvedName');

        if ($resolved instanceof Node\Name) {
            return $resolved->toString();
        }

        $namespaced = $name->getAttribute('namespacedName');

        if ($namespaced instanceof Node\Name) {
            return $namespaced->toString();
        }

        return $name->toString();
    }
}

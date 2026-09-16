<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Analysis;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use Vusys\Exocomp\Source\SourceFile;

/**
 * Finds anonymous classes anywhere in a file, including inside method bodies.
 *
 * This rides along with the name resolver on the single traversal the parser already does.
 * Doing it as a separate pass measured 14% slower over a 950k-line codebase, and the whole
 * argument for this tool's shape is that it must not lose to grep on time.
 */
final class AnonymousClassCollector extends NodeVisitorAbstract
{
    /** @var list<Node\Stmt\Class_> */
    private array $nodes = [];

    public function __construct(private readonly SourceFile $file) {}

    public function enterNode(Node $node): null
    {
        if (! $node instanceof Node\Stmt\Class_ || $node->name instanceof Node\Identifier) {
            return null;
        }

        $this->nodes[] = $node;

        return null;
    }

    /**
     * What was found, described in full.
     *
     * The members come from the same builder a named class goes through, because an anonymous
     * class addressed by its file is not a lesser kind of class — a migration's `up()` has to
     * print exactly as `OrderService::place` does.
     *
     * @return list<AnonymousClass>
     */
    public function found(DeclarationCollector $collector): array
    {
        $found = [];

        foreach ($this->nodes as $node) {
            $found[] = new AnonymousClass(
                line: $node->getStartLine(),
                column: $this->file->columnAt($node->getStartFilePos()),
                memberCount: count($node->stmts),
                extends: $node->extends instanceof Node\Name ? Analyser::resolvedName($node->extends) : null,
                declaration: $collector->anonymousDeclaration($node),
            );
        }

        return $found;
    }
}

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
    /** @var list<AnonymousClass> */
    private array $found = [];

    public function __construct(private readonly SourceFile $file) {}

    public function enterNode(Node $node): null
    {
        if (! $node instanceof Node\Stmt\Class_ || $node->name instanceof Node\Identifier) {
            return null;
        }

        $this->found[] = new AnonymousClass(
            line: $node->getStartLine(),
            column: $this->file->columnAt($node->getStartFilePos()),
            memberCount: count($node->stmts),
            extends: $node->extends instanceof Node\Name ? Analyser::resolvedName($node->extends) : null,
        );

        return null;
    }

    /**
     * @return list<AnonymousClass>
     */
    public function found(): array
    {
        return $this->found;
    }
}

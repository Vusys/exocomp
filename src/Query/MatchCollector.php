<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Query;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use Vusys\Exocomp\Source\SourceFile;

/**
 * The visitor half: keeps the enclosing-declaration stack and tries the pattern at every node.
 *
 * It is a separate visitor rather than logic inside the engine because php-parser already
 * walks the tree once to resolve names, and riding that traversal costs nothing where a second
 * pass would cost a full walk of every file in the corpus.
 */
final class MatchCollector extends NodeVisitorAbstract
{
    /** @var list<QueryMatch> */
    private array $matches = [];

    private string $namespace = '';

    /** @var list<array{Node\Stmt\ClassLike, string}> */
    private array $classes = [];

    /** @var list<array{Node\FunctionLike, string}> */
    private array $functions = [];

    private readonly Matcher $matcher;

    public function __construct(
        private readonly Pattern $pattern,
        private readonly SourceFile $file,
        private readonly Predicates $predicates,
    ) {
        $this->matcher = new Matcher;
    }

    public function enterNode(Node $node): null
    {
        if ($node instanceof Node\Stmt\Namespace_) {
            $this->namespace = $node->name?->toString() ?? '';
        }

        if ($node instanceof Node\Stmt\ClassLike) {
            $this->classes[] = [$node, $node->namespacedName?->toString() ?? $node->name?->toString() ?? '(anonymous)'];
        }

        if ($node instanceof Node\FunctionLike) {
            $this->functions[] = [$node, $this->nameOf($node)];
        }

        $captures = new Captures;

        if ($this->matcher->matches($this->pattern->root, $node, $captures)) {
            $class = $this->classes === [] ? null : $this->classes[count($this->classes) - 1];
            $function = $this->functions === [] ? null : $this->functions[count($this->functions) - 1];

            $match = new QueryMatch(
                file: $this->file,
                node: $node,
                captures: $captures,
                class: $class[0] ?? null,
                classFqn: $class[1] ?? null,
                function: $function[0] ?? null,
                functionName: $function[1] ?? null,
                namespace: $this->namespace,
            );

            if ($this->predicates->accepts($match)) {
                $this->matches[] = $match;
            }
        }

        return null;
    }

    public function leaveNode(Node $node): null
    {
        if ($node instanceof Node\Stmt\ClassLike) {
            array_pop($this->classes);
        }

        if ($node instanceof Node\FunctionLike) {
            array_pop($this->functions);
        }

        return null;
    }

    /**
     * @return list<QueryMatch>
     */
    public function matches(): array
    {
        return $this->matches;
    }

    private function nameOf(Node\FunctionLike $node): string
    {
        return match (true) {
            $node instanceof Node\Stmt\ClassMethod => $node->name->toString(),
            $node instanceof Node\Stmt\Function_ => $node->namespacedName?->toString() ?? $node->name->toString(),
            default => '{closure}',
        };
    }
}

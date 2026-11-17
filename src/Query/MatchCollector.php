<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Query;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use Vusys\Exocomp\Analysis\FileAnalysis;
use Vusys\Exocomp\Index\SymbolGraph;
use Vusys\Exocomp\Inference\ResolvedType;
use Vusys\Exocomp\Inference\Scope;
use Vusys\Exocomp\Inference\TypeResolver;
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

    private Scope $scope;

    /** @var list<Scope> the scopes a closure or nested function was entered from */
    private array $enclosing = [];

    public function __construct(
        private readonly Pattern $pattern,
        private readonly SourceFile $file,
        private readonly Predicates $predicates,
        private readonly ?TypeResolver $types = null,
        private readonly ?FileAnalysis $analysis = null,
        private readonly ?SymbolGraph $graph = null,
    ) {
        $this->matcher = new Matcher;
        $this->scope = new Scope(null, null);
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
            $this->enclosing[] = $this->scope;
            $this->scope = $this->scopeFor($node, $this->scope);
        }

        if ($node instanceof Node\Expr\Assign && $this->types instanceof TypeResolver && $this->analysis instanceof FileAnalysis) {
            $this->types->observeAssignment($node, $this->scope, $this->analysis->imports);
        }

        if ($node instanceof Node\Stmt\Foreach_ && $this->types instanceof TypeResolver && $this->analysis instanceof FileAnalysis) {
            $this->types->observeForeach($node, $this->scope, $this->analysis->imports);
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
                receiverType: $this->receiverTypeOf($node),
                graph: $this->graph,
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
            $this->scope = array_pop($this->enclosing) ?? new Scope(null, null);
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

    private function scopeFor(Node\FunctionLike $function, Scope $enclosing): Scope
    {
        if (! $this->types instanceof TypeResolver || ! $this->analysis instanceof FileAnalysis) {
            return new Scope(null, null);
        }

        $class = $this->classes === [] ? null : $this->classes[count($this->classes) - 1][1];

        return $this->types->scopeFor($class, null, $function, $this->analysis->imports, $enclosing);
    }

    /**
     * The static type of whatever a matched call or fetch is being made on.
     *
     * Only computed where a predicate could use it, because resolving a receiver walks the
     * hierarchy and there is no reason to pay for that on a query that never asks.
     */
    private function receiverTypeOf(Node $node): ?ResolvedType
    {
        if (! $this->predicates->needsReceiver() || ! $this->types instanceof TypeResolver || ! $this->analysis instanceof FileAnalysis) {
            return null;
        }

        $receiver = match (true) {
            $node instanceof Node\Expr\MethodCall,
            $node instanceof Node\Expr\NullsafeMethodCall,
            $node instanceof Node\Expr\PropertyFetch,
            $node instanceof Node\Expr\NullsafePropertyFetch => $node->var,
            $node instanceof Node\Expr\StaticCall,
            $node instanceof Node\Expr\StaticPropertyFetch,
            $node instanceof Node\Expr\ClassConstFetch => $node->class,
            default => null,
        };

        return $receiver instanceof Node
            ? $this->types->typeOf($receiver, $this->scope, $this->analysis->imports)
            : null;
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

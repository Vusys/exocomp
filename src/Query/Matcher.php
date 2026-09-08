<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Query;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar;
use PhpParser\Node\VariadicPlaceholder;

/**
 * Structural comparison of a pattern node against a subject node.
 *
 * The rule is that everything written literally must match literally, and only the three
 * documented constructs are wild. That direction matters: a matcher that is loose by default
 * produces confident false positives, which is the failure this whole tool is arranged against
 * — it would be a fancier grep with worse honesty.
 */
final class Matcher
{
    public function matches(Node $pattern, Node $subject, Captures $captures): bool
    {
        $metavariable = Pattern::metavariableName($pattern);

        if ($metavariable !== null) {
            // `$_` is the deliberate hole: match anything, remember nothing.
            if ($metavariable !== '_') {
                $captures->bind($metavariable, $subject);
            }

            return true;
        }

        if ($pattern instanceof Node\Stmt\Function_ && $subject instanceof Node\Stmt\ClassMethod) {
            // `function _M() {}` means any function-like declaration. A pattern language whose
            // word for "function" excluded methods would be answering a different question from
            // the one anyone asks, and the alternative — a second keyword — buys nothing.
            return $this->functionLikeMatches($pattern, $subject, $captures);
        }

        if ($pattern::class !== $subject::class) {
            return false;
        }

        if ($pattern instanceof Name && $subject instanceof Name) {
            // Class and function names are case-insensitive in PHP, and a pattern written with
            // the wrong case must still find them.
            return strcasecmp($pattern->toString(), $subject->toString()) === 0
                || strcasecmp($pattern->getLast(), $subject->getLast()) === 0;
        }

        if ($pattern instanceof Identifier && $subject instanceof Identifier) {
            return $this->identifiersMatch($pattern, $subject);
        }

        if ($pattern instanceof Scalar && $subject instanceof Scalar) {
            return $this->scalarsMatch($pattern, $subject);
        }

        return $this->subNodesMatch($pattern, $subject, $captures);
    }

    private function functionLikeMatches(Node\Stmt\Function_ $pattern, Node\Stmt\ClassMethod $subject, Captures $captures): bool
    {
        if (! $this->matches($pattern->name, $subject->name, $captures)) {
            return false;
        }

        if (! $this->listsMatch('params', array_values($pattern->params), array_values($subject->params), $pattern, $captures)) {
            return false;
        }

        if ($pattern->returnType instanceof Node
            && (! $subject->returnType instanceof Node || ! $this->matches($pattern->returnType, $subject->returnType, $captures))) {
            return false;
        }

        return $this->listsMatch('stmts', array_values($pattern->stmts), array_values($subject->stmts ?? []), $pattern, $captures);
    }

    private function identifiersMatch(Identifier $pattern, Identifier $subject): bool
    {
        // Method names fold case in PHP; property and constant names do not. Erring towards
        // case-insensitive here would make `->Value` and `->value` the same property, which
        // they are not — so exactness is the default and the caller can spell it.
        return $pattern->toString() === $subject->toString();
    }

    private function scalarsMatch(Scalar $pattern, Scalar $subject): bool
    {
        return array_all($pattern->getSubNodeNames(), fn (string $name): bool => $pattern->{$name} === $subject->{$name});
    }

    private function subNodesMatch(Node $pattern, Node $subject, Captures $captures): bool
    {
        foreach ($pattern->getSubNodeNames() as $name) {
            $left = $pattern->{$name};
            $right = $subject->{$name};

            if (is_array($left)) {
                if (! is_array($right) || ! $this->listsMatch($name, array_values($left), array_values($right), $pattern, $captures)) {
                    return false;
                }

                continue;
            }

            if ($left === null) {
                // A pattern that says nothing about a subnode is not asserting it is absent —
                // except for the two places where absence is the only way to write "anything",
                // handled in listsMatch().
                continue;
            }

            if ($left instanceof Node) {
                if (! $right instanceof Node || ! $this->matches($left, $right, $captures)) {
                    return false;
                }

                continue;
            }

            if ($left !== $right) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<mixed>  $pattern
     * @param  list<mixed>  $subject
     */
    private function listsMatch(string $name, array $pattern, array $subject, Node $owner, Captures $captures): bool
    {
        // `foo(...)` — the first-class callable syntax — is how a pattern says "any arguments".
        if ($this->isVariadicWildcard($pattern)) {
            return true;
        }

        // An empty body or parameter list in the pattern means "any". Writing it this way keeps
        // the pattern parseable — `function f(...)` is not PHP, only `f(...)` is — and being
        // able to check a pattern by eye before running it over a corpus is worth more than the
        // ability to say "exactly none", which `--param-count 0` covers anyway.
        if ($pattern === [] && in_array($name, ['stmts', 'params'], true) && ! $owner instanceof Node\Stmt\ClassLike) {
            return true;
        }

        if (count($pattern) !== count($subject)) {
            return false;
        }

        foreach ($pattern as $index => $item) {
            $other = $subject[$index] ?? null;

            if ($item instanceof Node) {
                if (! $other instanceof Node || ! $this->matches($this->unwrap($item), $this->unwrap($other), $captures)) {
                    return false;
                }

                continue;
            }

            if ($item !== $other) {
                return false;
            }
        }

        return true;
    }

    /**
     * An argument or parameter is a wrapper around the thing the caller cares about; comparing
     * the wrappers would make `foo($X)` fail to match `foo($order)` over by-reference flags
     * nobody wrote in the pattern.
     */
    private function unwrap(Node $node): Node
    {
        if ($node instanceof Arg) {
            return $node->value;
        }

        if ($node instanceof Param) {
            return $node->var;
        }

        return $node;
    }

    /**
     * @param  list<mixed>  $items
     */
    private function isVariadicWildcard(array $items): bool
    {
        return count($items) === 1 && ($items[0] ?? null) instanceof VariadicPlaceholder;
    }
}

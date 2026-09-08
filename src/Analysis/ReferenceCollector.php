<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Analysis;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Every name a file *mentions*, as opposed to every name it declares.
 *
 * This is the index's coarse filter, and it is what makes a corpus-wide question affordable. A
 * structural query for `$x->recordUsage(...)` cannot match in a file that does not contain
 * the text "recordUsage", so the only files worth parsing are the ones the index says
 * mention it — three, rather than six and a half thousand.
 *
 * Only the names are kept, not their positions. Positions belong to a parse, and a parse is
 * what confirms a candidate anyway; storing them would multiply the index for information that
 * is re-derived the moment it is used.
 */
final class ReferenceCollector extends NodeVisitorAbstract
{
    /** @var array<string, true> */
    private array $names = [];

    public function enterNode(Node $node): null
    {
        match (true) {
            $node instanceof Node\Expr\MethodCall,
            $node instanceof Node\Expr\NullsafeMethodCall,
            $node instanceof Node\Expr\StaticCall => $this->take($node->name),
            $node instanceof Node\Expr\PropertyFetch,
            $node instanceof Node\Expr\NullsafePropertyFetch,
            $node instanceof Node\Expr\StaticPropertyFetch => $this->take($node->name),
            $node instanceof Node\Expr\ClassConstFetch => $this->take($node->name),
            $node instanceof Node\Expr\FuncCall,
            $node instanceof Node\Expr\ConstFetch,
            $node instanceof Node\Expr\New_,
            $node instanceof Node\Expr\Instanceof_ => $this->take($node instanceof Node\Expr\New_ || $node instanceof Node\Expr\Instanceof_ ? $node->class : $node->name),
            $node instanceof Node\Name => $this->take($node),
            $node instanceof Node\AttributeGroup => null,
            default => null,
        };

        return null;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->names);
    }

    private function take(mixed $name): void
    {
        $text = match (true) {
            $name instanceof Node\Identifier => $name->toString(),
            $name instanceof Node\Name => $name->getLast(),
            default => null,
        };

        if ($text !== null && $text !== '') {
            $this->names[$text] = true;
        }
    }
}

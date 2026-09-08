<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Graph;

use PhpParser\Node\FunctionLike;
use Vusys\Exocomp\Analysis\FileAnalysis;

/**
 * A declaration the walk can step through: where it is, and the tree of what it does.
 *
 * `fqn` is the address the body was actually found at, which is not always the address asked
 * for — a method called on a subclass is frequently declared on a parent or in a trait, and
 * reporting the walk as passing through the class that declares it is what makes the path
 * navigable.
 */
final readonly class Body
{
    public function __construct(
        public string $fqn,
        public string $path,
        public FunctionLike $node,
        public FileAnalysis $analysis,
        public ?string $classFqn,
        public ?string $parentFqn,
    ) {}
}

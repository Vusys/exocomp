<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Project;

use PhpParser\Node\Stmt\ClassLike;
use Vusys\Exocomp\Analysis\FileAnalysis;

/**
 * One class-like, with the parsed tree it was declared in.
 *
 * The framework readers all work the same way — find a class, then look at what its body
 * literally says — so they all need the node rather than the index row. The declaration list
 * an index holds is the wrong shape for that: it records that `$fillable` exists, not what it
 * was set to.
 */
final readonly class LoadedClass
{
    public function __construct(
        public string $fqn,
        public string $path,
        public FileAnalysis $analysis,
        public ClassLike $node,
    ) {}
}

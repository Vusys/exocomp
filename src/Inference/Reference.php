<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Inference;

use Vusys\Exocomp\Symbol\Confidence;

/**
 * One place a symbol is referred to, and how sure the tool is about it.
 *
 * The confidence is the product here. A rename with 98% recall is not 98% good, it is broken —
 * and in PHP it breaks at runtime, on whichever path happens to execute, rather than at build
 * time. So the useful output is not a list of references but a list plus an honest account of
 * what is missing from it.
 */
final readonly class Reference
{
    public function __construct(
        public string $path,
        public int $line,
        public int $column,
        public string $kind,
        public Confidence $confidence,
        public string $reason,
        public string $snippet,
        public string $context,
    ) {}
}

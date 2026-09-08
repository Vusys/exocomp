<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Project;

use RuntimeException;

/**
 * A name did not resolve to a declaration. This is exit code 1 — "not found" — and deliberately
 * distinct from exit code 2, "error": an agent chaining commands with `&&` needs to tell a
 * missing symbol apart from a broken invocation, and collapsing them into one non-zero code
 * makes both untrustworthy.
 */
final class SymbolNotFound extends RuntimeException
{
    public function __construct(public readonly string $address, string $reason)
    {
        parent::__construct($reason);
    }
}

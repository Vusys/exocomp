<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Source;

use RuntimeException;

/**
 * A file could not be parsed.
 *
 * `nikic/php-parser` is not error-tolerant, and an agent frequently queries a file it has just
 * half-edited — so the parser fails exactly when help is most wanted. The contract from here
 * up is that one unparseable file degrades that file and never aborts a whole command; this
 * exception carries the position so the caller can say where, rather than only that.
 */
final class ParseFailure extends RuntimeException
{
    public function __construct(
        public readonly string $path,
        public readonly int $startLine,
        string $reason,
    ) {
        parent::__construct($reason);
    }
}

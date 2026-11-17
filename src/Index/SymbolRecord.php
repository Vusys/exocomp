<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Index;

use Vusys\Exocomp\Analysis\Declaration;
use Vusys\Exocomp\Symbol\SymbolKind;

/**
 * One indexed declaration, as it comes back from a query.
 *
 * This is deliberately not a {@see Declaration}: that carries byte
 * offsets and a members tree, which are properties of a parse rather than of an index row. When
 * a command needs the exact source it re-reads and re-parses the file, because a span served
 * from cache is a span that might have moved.
 */
final readonly class SymbolRecord
{
    /**
     * @param  array<string, string>  $inferredElements  see {@see Declaration}
     */
    public function __construct(
        public string $path,
        public string $fqn,
        public string $name,
        public SymbolKind $kind,
        public ?string $owner,
        public int $line,
        public int $endLine,
        public int $column,
        public string $signature,
        public string $fullSignature,
        public string $modifiers,
        public bool $isPromoted,
        public bool $hasBody,
        public ?string $typeFqn = null,
        public ?string $inferredFqn = null,
        public ?string $inferredReason = null,
        public array $inferredElements = [],
    ) {}

    public function isClassLike(): bool
    {
        return $this->kind->isClassLike();
    }
}

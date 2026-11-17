<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Index;

use Vusys\Exocomp\Analysis\Declaration;
use Vusys\Exocomp\Analysis\FileAnalysis;
use Vusys\Exocomp\Symbol\SymbolKind;

/**
 * Everything the index keeps about one file's *contents*, with no mention of where that file
 * lives.
 *
 * The separation is the whole point of the cache design. Facts belong to a content hash, not to
 * a path, so a second worktree checked out from the same base shares them: its files hash to
 * blobs that are already extracted, and indexing it costs a walk and a hash rather than a
 * parse. That is the property that decides whether this tool survives contact with the
 * disposable-worktree workflow it is built for.
 */
final readonly class FileFacts
{
    /**
     * @param  list<array<string, string|int|bool|null>>  $symbols
     * @param  list<array{from: string, kind: string, to: string}>  $edges
     * @param  list<string>  $namespaces
     * @param  list<string>  $referencedNames  every name the file mentions, for narrowing searches
     */
    public function __construct(
        public array $symbols,
        public array $edges,
        public array $namespaces,
        public array $referencedNames = [],
    ) {}

    public static function from(FileAnalysis $analysis): self
    {
        $symbols = [];
        $edges = [];

        foreach ($analysis->declarations as $declaration) {
            $symbols[] = self::symbol($declaration, null);

            foreach ($declaration->members as $member) {
                $symbols[] = self::symbol($member, $declaration->fqn);
            }

            foreach ([['extends', $declaration->extends], ['implements', $declaration->implements], ['uses', $declaration->traits]] as [$kind, $targets]) {
                foreach ($targets as $target) {
                    $edges[] = ['from' => $declaration->fqn, 'kind' => $kind, 'to' => $target];
                }
            }
        }

        return new self($symbols, $edges, $analysis->namespaces, $analysis->referencedNames);
    }

    /**
     * @return array<string, string|int|bool|null>
     */
    private static function symbol(Declaration $declaration, ?string $owner): array
    {
        return [
            'fqn' => $declaration->fqn,
            'name' => $declaration->name,
            'kind' => $declaration->kind->value,
            'owner' => $owner,
            'line' => $declaration->startLine,
            'end_line' => $declaration->endLine,
            'col' => $declaration->startColumn,
            'span_line' => $declaration->spanStartLine,
            'signature' => $declaration->signature,
            'full_signature' => $declaration->fullSignature,
            'modifiers' => implode(' ', $declaration->modifiers),
            'promoted' => $declaration->isPromoted,
            'has_body' => $declaration->hasBody,
            'type_fqn' => $declaration->typeFqn,
            'inferred_fqn' => $declaration->inferredFqn,
            'inferred_reason' => $declaration->inferredReason,
            'inferred_elements' => $declaration->inferredElements === [] ? null : json_encode($declaration->inferredElements, JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * Member names are case-sensitive for properties, constants and enum cases but not for
     * methods and class-likes, so the index stores a lowered form only where lowering is
     * actually correct. Lowering everything would make `$obj->Value` and `$obj->value` the same
     * row, which they are not.
     */
    public static function caseInsensitive(string $kind): bool
    {
        return match (SymbolKind::tryFrom($kind)) {
            SymbolKind::Method, SymbolKind::FunctionDecl => true,
            null => false,
            default => SymbolKind::from($kind)->isClassLike(),
        };
    }
}

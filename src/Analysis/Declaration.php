<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Analysis;

use Vusys\Exocomp\Symbol\SymbolKind;

/**
 * One named element found in a file, with the exact byte span that constitutes it.
 *
 * The span is the whole point. `show` exists to end the `grep -n … | sed -n 'N,+6p'` dance,
 * whose failure mode is a body one line longer than the guess — a truncated method that reads
 * as a complete one. Spans here come from the parser, so they are exact, and they include any
 * leading docblock and attributes because those are part of the element a reader asked for.
 *
 * @phpstan-type Origin array{kind: string, name: string}
 */
final readonly class Declaration
{
    /**
     * @param  list<self>  $members  members declared directly in this class-like
     * @param  list<string>  $extends  resolved FQNs (interfaces may extend several)
     * @param  list<string>  $implements  resolved FQNs
     * @param  list<string>  $traits  resolved FQNs of directly used traits
     * @param  list<string>  $modifiers  rendered in source order: final, abstract, static, readonly, visibility
     * @param  ?string  $typeFqn  the resolved class-like type this declares — a property's type or
     *                            a method's return type — where it is a single class name.
     *                            Null for scalars, unions and anything else, because a receiver
     *                            cannot be resolved through those and pretending otherwise is
     *                            how a tool starts guessing.
     * @param  ?string  $inferredFqn  where nothing native declares a class, the one the class's own
     *                                code or docblock implies (see BodyTypes), with the evidence in
     *                                $inferredReason. Never a declaration: it is read as inferred.
     */
    public function __construct(
        public SymbolKind $kind,
        public string $name,
        public string $fqn,
        public int $startLine,
        public int $endLine,
        public int $startColumn,
        public int $startOffset,
        public int $endOffset,
        public int $spanStartLine,
        public int $spanStartOffset,
        public string $signature,
        public string $fullSignature,
        public array $modifiers = [],
        public array $members = [],
        public array $extends = [],
        public array $implements = [],
        public array $traits = [],
        public bool $isPromoted = false,
        public bool $hasBody = false,
        public ?string $typeFqn = null,
        public ?string $inferredFqn = null,
        public ?string $inferredReason = null,
    ) {}

    public function isClassLike(): bool
    {
        return $this->kind->isClassLike();
    }

    /**
     * @return list<self>
     */
    public function membersOfKind(SymbolKind ...$kinds): array
    {
        return array_values(array_filter(
            $this->members,
            static fn (self $member): bool => in_array($member->kind, $kinds, true),
        ));
    }

    /**
     * Finds a member by the name half of a symbol address.
     *
     * `Foo::$bar` can only be a property. `Foo::bar` is ambiguous — a method, a constant and
     * an enum case are all legal readings — so those are tried first, and a property only as a
     * fallback, so an exact constant is never shadowed by a same-named property.
     */
    public function member(string $name, bool $isProperty): ?self
    {
        if ($isProperty) {
            return $this->firstMatching(
                static fn (self $member): bool => $member->kind === SymbolKind::Property && $member->name === $name,
            );
        }

        return $this->firstMatching(static fn (self $member): bool => self::namedMemberMatches($member, $name))
            ?? $this->firstMatching(
                static fn (self $member): bool => $member->kind === SymbolKind::Property && $member->name === $name,
            );
    }

    /**
     * Method names are case-insensitive in PHP; constant and enum-case names are not. Getting
     * this backwards is a real trap — `foo()` and `Foo()` are the same method, while `BAR` and
     * `bar` are different constants — and an index that lowercases everything answers
     * confidently and wrongly.
     */
    private static function namedMemberMatches(self $member, string $name): bool
    {
        if ($member->kind === SymbolKind::Method) {
            return strcasecmp($member->name, $name) === 0;
        }

        return match ($member->kind) {
            SymbolKind::Constant, SymbolKind::EnumCase => $member->name === $name,
            default => false,
        };
    }

    /**
     * @param  callable(self): bool  $matches
     */
    private function firstMatching(callable $matches): ?self
    {
        foreach ($this->members as $member) {
            if ($matches($member)) {
                return $member;
            }
        }

        return null;
    }
}

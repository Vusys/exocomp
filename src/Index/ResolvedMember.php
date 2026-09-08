<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Index;

/**
 * A member as seen from some class, together with where it came from.
 *
 * Origin is the entire value-add over reading the file: a reader who wanted to know whether
 * `orders()` is declared here, inherited, or flattened in from a trait cannot get that from any
 * textual search, and 32 of the measured searches were reconstructing it by hand from
 * `extends`/`implements`/`use` lines.
 */
final readonly class ResolvedMember
{
    public function __construct(
        public SymbolRecord $record,
        public MemberOrigin $origin,
        public ?string $originFqn,
        public bool $isOverridden,
    ) {}

    public function originLabel(): string
    {
        return match ($this->origin) {
            MemberOrigin::Own => 'own',
            MemberOrigin::Trait_ => 'from trait '.($this->originFqn ?? '?'),
            MemberOrigin::Inherited => 'inherited from '.($this->originFqn ?? '?'),
            MemberOrigin::Interface_ => 'required by '.($this->originFqn ?? '?'),
        };
    }
}

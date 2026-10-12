<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Inference;

use Vusys\Exocomp\Index\MemberOrigin;
use Vusys\Exocomp\Index\SymbolGraph;
use Vusys\Exocomp\Index\SymbolRecord;
use Vusys\Exocomp\Symbol\Identifier;
use Vusys\Exocomp\Symbol\SymbolKind;
use Vusys\Exocomp\Symbol\SymbolRef;

/**
 * Where a member really is, when it is not where it was asked for.
 *
 * Asking for a member on the wrong class is the commonest member miss by far, and it is almost
 * never a typo: the member lives on a trait the class uses, on the subclass that actually does
 * the work, or on a sibling that happens to share a parent. The index holds every declaration of
 * the name, so where it is declared — and how each of those classes stands to the one asked
 * about — is a fact to hand back rather than a second command to make the caller run.
 *
 * The same rule as for a guessed namespace decides when to answer rather than list: only when
 * exactly one place could have been meant. A member reachable from the class through its parents
 * and traits is the one PHP would call, so that is always the answer. Failing that, a single
 * declaration on a class related to the one asked about is answered; two or more are a genuine
 * ambiguity, and choosing between them would be the guess this tool exists not to make.
 */
final readonly class MemberElsewhere
{
    private const int LISTED = 5;

    private Members $members;

    public function __construct(private SymbolGraph $graph)
    {
        $this->members = new Members($graph);
    }

    /**
     * `answer` is the declaration to use instead, when exactly one could have been meant, and
     * `reachable` whether PHP would reach it from the class asked about; `note` says what was
     * done or where to look.
     *
     * @return array{answer: ?SymbolRecord, reachable: bool, note: ?string}
     */
    public function find(SymbolRef $target): array
    {
        $owner = $target->owner;
        $member = (string) $target->member;

        foreach ($this->kinds($target) as $kind) {
            $reachable = $this->members->declared($owner, $member, $kind);

            if ($reachable instanceof SymbolRecord && $reachable->owner !== null) {
                return [
                    'answer' => $reachable,
                    'reachable' => true,
                    'note' => sprintf(
                        '%s does not declare "%s" itself — answered %s, from %s',
                        $owner,
                        $member,
                        $reachable->fqn,
                        $this->relation($owner, $reachable->owner) ?? 'an ancestor',
                    ),
                ];
            }
        }

        $declarations = [];

        foreach ($this->graph->byName($member) as $record) {
            if ($record->owner !== null && $this->matches($record, $target)) {
                $declarations[] = ['record' => $record, 'relation' => $this->relation($owner, $record->owner)];
            }
        }

        if ($declarations === []) {
            return ['answer' => null, 'reachable' => false, 'note' => null];
        }

        $related = array_values(array_filter($declarations, static fn (array $d): bool => $d['relation'] !== null));

        if (count($related) === 1) {
            return [
                'answer' => $related[0]['record'],
                'reachable' => false,
                'note' => sprintf(
                    '%s has no member "%s" — answered %s instead, from %s',
                    $owner,
                    $member,
                    $related[0]['record']->fqn,
                    $related[0]['relation'],
                ),
            ];
        }

        $listed = array_map(
            static fn (array $d): string => $d['record']->fqn.($d['relation'] === null ? '' : ' ('.$d['relation'].')'),
            array_slice([...$related, ...array_filter($declarations, static fn (array $d): bool => $d['relation'] === null)], 0, self::LISTED),
        );

        return ['answer' => null, 'reachable' => false, 'note' => sprintf(
            '"%s" is declared as %s%s',
            $member,
            implode(', ', $listed),
            count($declarations) > self::LISTED ? sprintf(' and %d more — try: exocomp locate %s', count($declarations) - self::LISTED, $member) : '',
        )];
    }

    /**
     * How a declaring class stands to the one asked about, from the asked-about class's point
     * of view — or null when the two are unrelated, which includes siblings: sharing a parent is
     * not a reason to believe one was meant for the other.
     */
    private function relation(string $asked, string $declaring): ?string
    {
        if (Identifier::fold($asked) === Identifier::fold($declaring)) {
            return null;
        }

        $up = $this->origin($this->graph->ancestorsOf($asked), $declaring);

        if ($up instanceof MemberOrigin) {
            return match ($up) {
                MemberOrigin::Trait_ => 'a trait it uses',
                MemberOrigin::Interface_ => 'an interface it implements',
                default => 'an ancestor',
            };
        }

        $down = $this->origin($this->graph->ancestorsOf($declaring), $asked);

        if ($down instanceof MemberOrigin) {
            return match ($down) {
                MemberOrigin::Trait_ => 'a class that uses it',
                MemberOrigin::Interface_ => 'an implementor',
                default => 'a subclass',
            };
        }

        foreach ($this->graph->traitUsers($declaring, recursive: false) as $user) {
            if ($this->origin($this->graph->ancestorsOf($user->fqn), $asked) instanceof MemberOrigin) {
                return sprintf('a trait used by its subclass %s', $user->fqn);
            }
        }

        return null;
    }

    /**
     * @param  array<string, MemberOrigin>  $ancestors
     */
    private function origin(array $ancestors, string $fqn): ?MemberOrigin
    {
        foreach ($ancestors as $ancestor => $origin) {
            if (Identifier::fold($ancestor) === Identifier::fold($fqn)) {
                return $origin;
            }
        }

        return null;
    }

    /**
     * A `$`-prefixed address can only be a property. A bare one is how a method, a constant or
     * an enum case is written — and how a property often is too, by a caller who left off the
     * sigil — so all of them are candidates.
     *
     * @return list<SymbolKind>
     */
    private function kinds(SymbolRef $target): array
    {
        return $target->memberIsProperty
            ? [SymbolKind::Property]
            : [SymbolKind::Method, SymbolKind::Property, SymbolKind::Constant, SymbolKind::EnumCase];
    }

    private function matches(SymbolRecord $record, SymbolRef $target): bool
    {
        if (! in_array($record->kind, $this->kinds($target), true)) {
            return false;
        }

        // Method names fold case in PHP; the other member kinds do not.
        return $record->kind === SymbolKind::Method
            ? strcasecmp($record->name, (string) $target->member) === 0
            : $record->name === $target->member;
    }
}

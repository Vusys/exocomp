<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Index;

use Vusys\Exocomp\Symbol\Identifier;
use Vusys\Exocomp\Symbol\SymbolKind;

/**
 * The inheritance graph, read from the index.
 *
 * Everything here is syntactic — `extends`, `implements`, `use` — which is why it lands before
 * type inference despite answering a large share of the questions inference is usually assumed
 * to be needed for. Working out that a method is inherited from a particular parent requires
 * knowing the shape of the hierarchy, not the types flowing through it.
 */
final class SymbolGraph
{
    /** @var array<string, list<SymbolRecord>> */
    private array $memoisedFqn = [];

    /** @var array<string, array<string, MemberOrigin>> */
    private array $memoisedAncestors = [];

    /** @var array<string, array{members: list<ResolvedMember>, unreachable: list<UnreachableAncestor>}> */
    private array $memoisedMembers = [];

    /** @var array<string, list<SymbolRecord>> */
    private array $memoisedMembersOf = [];

    public function __construct(
        private readonly IndexStore $store,
        private readonly string $root,
        private readonly ?Freshness $freshness = null,
    ) {}

    /**
     * @return list<SymbolRecord>
     */
    public function byName(string $name, ?SymbolKind $kind = null): array
    {
        // A dotted or separated name is a fully-qualified one; anything else is a short name,
        // which is what `find -name '*Foo*.php'` was reaching for.
        // A name the caller typed escalates to a full pass if nothing matches; a name this
        // class looked up on its own behalf does not. Walking a hierarchy asks about ancestors
        // that are legitimately absent — un-indexed framework base classes, mostly — and every
        // one of those must not cost a walk of the corpus to confirm what it already knows.
        $fetch = str_contains($name, '\\')
            ? fn (): array => $this->store->byFqn($this->root, $name)
            : fn (): array => $this->store->byName($this->root, $name, $kind);

        return $this->seeking($fetch);
    }

    /**
     * The files that mention every one of these names.
     *
     * The index records what each file refers to, which is what makes a corpus-wide question
     * affordable: a name is written in a handful of files, not in six and a half thousand, and
     * only the handful is parsed.
     *
     * @return list<string>
     */
    public function pathsMentioning(string ...$names): array
    {
        return $this->store->pathsMentioning($this->root, array_values($names));
    }

    /**
     * @return list<SymbolRecord>
     */
    public function byFqn(string $fqn): array
    {
        return $this->memoisedFqn[Identifier::fold($fqn)] ??= $this->validated(
            fn (): array => $this->store->byFqn($this->root, $fqn),
        );
    }

    /**
     * As {@see self::classLike()}, for a name the caller supplied: a miss is worth one full
     * pass, because a class written since the last one would otherwise be reported as not
     * existing.
     */
    public function namedClassLike(string $fqn): ?SymbolRecord
    {
        $found = $this->classLike($fqn);

        if ($found instanceof SymbolRecord || ! $this->freshness instanceof Freshness || ! $this->freshness->full()) {
            return $found;
        }

        $this->forgetCaches();

        return $this->classLike($fqn);
    }

    public function classLike(string $fqn): ?SymbolRecord
    {
        foreach ($this->byFqn($fqn) as $record) {
            if ($record->isClassLike()) {
                return $record;
            }
        }

        return null;
    }

    /**
     * @return list<SymbolRecord>
     */
    public function subclasses(string $fqn, bool $recursive): array
    {
        return $recursive
            ? $this->closure($fqn, ['extends'], ['extends'])
            : $this->validated(fn (): array => $this->store->edgesTo($this->root, 'extends', $fqn));
    }

    /**
     * Everything that satisfies an interface, however indirectly.
     *
     * The closure has to follow both edge kinds at once. A class implementing an interface that
     * *extends* the one asked about is an implementor, and so is a class extending such a
     * class — following only `extends`, or only `implements`, silently drops whole families,
     * and an audit that missed a family would be worse than no audit.
     *
     * @return list<SymbolRecord>
     */
    public function implementors(string $fqn, bool $recursive): array
    {
        return $recursive
            ? $this->closure($fqn, ['implements', 'extends'], ['implements', 'extends'])
            : $this->validated(fn (): array => $this->store->edgesTo($this->root, 'implements', $fqn));
    }

    /**
     * Trait users, and — transitively — the subclasses that inherit those members with them.
     *
     * @return list<SymbolRecord>
     */
    public function traitUsers(string $fqn, bool $recursive): array
    {
        return $recursive
            ? $this->closure($fqn, ['uses'], ['extends', 'uses'])
            : $this->validated(fn (): array => $this->store->edgesTo($this->root, 'uses', $fqn));
    }

    /**
     * Breadth-first closure downwards through the hierarchy.
     *
     * `$seed` names the edge kinds that reach the first generation; `$follow` names the ones
     * followed from there. They differ for traits, where the first hop is a `use` and every
     * later one is an `extends` or a re-`use`.
     *
     * @param  list<string>  $seed
     * @param  list<string>  $follow
     * @return list<SymbolRecord>
     */
    private function closure(string $fqn, array $seed, array $follow): array
    {
        $found = [];
        $queue = [[$fqn, $seed]];
        $visited = [Identifier::fold($fqn) => true];

        while ($queue !== []) {
            /** @var array{0: string, 1: list<string>} $item */
            $item = array_shift($queue);
            [$current, $kinds] = $item;

            foreach ($kinds as $kind) {
                $edges = $this->validated(fn (): array => $this->store->edgesTo($this->root, $kind, $current));

                foreach ($edges as $record) {
                    $key = Identifier::fold($record->fqn);

                    if (isset($visited[$key])) {
                        continue;
                    }

                    $visited[$key] = true;
                    $found[$key] = $record;
                    $queue[] = [$record->fqn, $follow];
                }
            }
        }

        return array_values($found);
    }

    /**
     * The full API of a class: its own members, then those flattened in from traits, then those
     * inherited, then those an interface requires.
     *
     * Precedence follows PHP's: a class's own declaration wins over a trait's, and a trait's
     * over an inherited one. A member displaced that way is marked as overriding rather than
     * dropped, since "this class overrides that" is usually the thing being asked.
     *
     * @return array{members: list<ResolvedMember>, unreachable: list<UnreachableAncestor>}
     */
    public function members(string $fqn, bool $includeInherited = true): array
    {
        // Memoised because inference asks this constantly: every receiver whose type is known
        // needs the target class's members looked up, and without a cache that is a fresh walk
        // of the hierarchy — and a fresh round of database queries — per call site. Measured on
        // a large codebase, caching took a reference search from 31 seconds to under two.
        $key = Identifier::fold($fqn).($includeInherited ? '+' : '-');

        if (isset($this->memoisedMembers[$key])) {
            return $this->memoisedMembers[$key];
        }

        $members = [];
        $unreachable = [];
        $taken = [];

        $this->gather($fqn, MemberOrigin::Own, null, $members, $taken, $unreachable, $includeInherited, [], 0);

        return $this->memoisedMembers[$key] = ['members' => $members, 'unreachable' => $unreachable];
    }

    /**
     * Every place a member of this name is declared across the class's own hierarchy — the
     * ancestors it overrides and the descendants that override it.
     *
     * @return list<ResolvedMember>
     */
    public function overrides(string $ownerFqn, string $memberName): array
    {
        $found = [];

        foreach ($this->ancestorsOf($ownerFqn) as $ancestorFqn => $relation) {
            foreach ($this->declaredMember($ancestorFqn, $memberName) as $record) {
                $found[] = new ResolvedMember($record, $relation, $ancestorFqn, false);
            }
        }

        foreach ($this->subclasses($ownerFqn, true) as $descendant) {
            foreach ($this->declaredMember($descendant->fqn, $memberName) as $record) {
                $found[] = new ResolvedMember($record, MemberOrigin::Inherited, $descendant->fqn, true);
            }
        }

        return $found;
    }

    /**
     * Ancestors reachable from a class, mapped to how they were reached. Breadth-first and
     * cycle-guarded, because a malformed hierarchy must produce a wrong answer rather than a
     * hang.
     *
     * @return array<string, MemberOrigin>
     */
    public function ancestorsOf(string $fqn): array
    {
        $key = Identifier::fold($fqn);

        if (isset($this->memoisedAncestors[$key])) {
            return $this->memoisedAncestors[$key];
        }

        $found = [];
        $queue = [[$fqn, null]];
        $seen = [Identifier::fold($fqn) => true];

        while ($queue !== []) {
            /** @var array{0: string, 1: MemberOrigin|null} $item */
            $item = array_shift($queue);
            [$current, $via] = $item;

            if ($via instanceof MemberOrigin) {
                $found[$current] = $via;
            }

            foreach ($this->store->edgesFrom($this->root, $current) as $edge) {
                $key = Identifier::fold($edge['to']);

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $queue[] = [$edge['to'], $this->originFor($edge['kind'], $via)];
            }
        }

        return $found;
    }

    /**
     * @param  list<ResolvedMember>  $members
     * @param  array<string, true>  $taken
     * @param  list<UnreachableAncestor>  $unreachable
     * @param  array<string, true>  $visited
     */
    private function gather(
        string $fqn,
        MemberOrigin $origin,
        ?string $originFqn,
        array &$members,
        array &$taken,
        array &$unreachable,
        bool $includeInherited,
        array $visited,
        int $depth,
    ): void {
        $key = Identifier::fold($fqn);

        if (isset($visited[$key]) || $depth > 64) {
            return;
        }

        $visited[$key] = true;

        foreach ($this->declaredMembers($fqn) as $record) {
            $slot = $this->slot($record);

            if (isset($taken[$slot])) {
                continue;
            }

            $taken[$slot] = true;
            $members[] = new ResolvedMember($record, $origin, $originFqn, $depth > 0);
        }

        if (! $includeInherited) {
            return;
        }

        // Traits first, then the parent chain, then interfaces — the order PHP itself resolves
        // in, so that what is reported is what would actually be called.
        $edges = $this->store->edgesFrom($this->root, $fqn);
        $order = ['uses' => [], 'extends' => [], 'implements' => []];

        foreach ($edges as $edge) {
            if (isset($order[$edge['kind']])) {
                $order[$edge['kind']][] = $edge['to'];
            }
        }

        foreach ($order as $kind => $targets) {
            foreach ($targets as $target) {
                if (! $this->classLike($target) instanceof SymbolRecord) {
                    $unreachable[] = new UnreachableAncestor(
                        $target,
                        $kind,
                        'not in the index — run: exocomp index --warm --vendor',
                    );

                    continue;
                }

                $this->gather(
                    $target,
                    $this->originFor($kind, null),
                    $target,
                    $members,
                    $taken,
                    $unreachable,
                    true,
                    $visited,
                    $depth + 1,
                );
            }
        }
    }

    /**
     * @return list<SymbolRecord>
     */
    private function declaredMember(string $ownerFqn, string $memberName): array
    {
        $found = [];

        foreach ($this->declaredMembers($ownerFqn) as $record) {
            $matches = $record->kind === SymbolKind::Method
                ? strcasecmp($record->name, $memberName) === 0
                : $record->name === $memberName;

            if ($matches) {
                $found[] = $record;
            }
        }

        return $found;
    }

    /**
     * A member reached through a trait or a parent is still that member; one reached through an
     * interface is only a requirement. Both are reported, and the distinction is kept because
     * "declared somewhere up the chain" and "must be declared here" are different facts.
     */
    private function originFor(string $kind, ?MemberOrigin $inheritedVia): MemberOrigin
    {
        $direct = match ($kind) {
            'uses' => MemberOrigin::Trait_,
            'implements' => MemberOrigin::Interface_,
            default => MemberOrigin::Inherited,
        };

        // An interface reached through another interface is still an interface requirement.
        return $inheritedVia === MemberOrigin::Interface_ ? MemberOrigin::Interface_ : $direct;
    }

    /**
     * A class's own declarations, cached.
     *
     * @return list<SymbolRecord>
     */
    private function declaredMembers(string $fqn): array
    {
        return $this->memoisedMembersOf[Identifier::fold($fqn)] ??= $this->validated(
            fn (): array => $this->store->membersOf($this->root, $fqn),
        );
    }

    /**
     * Runs a lookup, then confirms the files its answer came from are still what the index
     * thinks they are. If any had moved on they are re-read and the lookup runs once more, so
     * what comes back reflects the working tree rather than a memory of it.
     *
     * @param  callable(): list<SymbolRecord>  $fetch
     * @return list<SymbolRecord>
     */
    private function validated(callable $fetch): array
    {
        $records = $fetch();

        if (! $this->freshness instanceof Freshness || $records === []) {
            return $records;
        }

        $paths = array_values(array_unique(array_map(
            static fn (SymbolRecord $record): string => $record->path,
            $records,
        )));

        if (! $this->freshness->refresh($paths)) {
            return $records;
        }

        $this->forgetCaches();

        return $fetch();
    }

    /**
     * As {@see self::validated()}, but a result of nothing is not accepted until the whole
     * corpus has been brought up to date once — because "no such symbol" is exactly the answer
     * a newly written class would produce from a stale index, and it is the one answer a caller
     * cannot tell apart from the truth.
     *
     * @param  callable(): list<SymbolRecord>  $fetch
     * @return list<SymbolRecord>
     */
    private function seeking(callable $fetch): array
    {
        $records = $this->validated($fetch);

        if ($records !== [] || ! $this->freshness instanceof Freshness) {
            return $records;
        }

        return $this->freshness->full() ? $fetch() : $records;
    }

    /**
     * Everything derived from the index has to go when the index moves underneath it, or a
     * cache that was correct a moment ago starts answering from a memory of the tree.
     */
    private function forgetCaches(): void
    {
        $this->memoisedFqn = [];
        $this->memoisedAncestors = [];
        $this->memoisedMembers = [];
        $this->memoisedMembersOf = [];
    }

    private function slot(SymbolRecord $record): string
    {
        $name = $record->kind === SymbolKind::Method ? Identifier::fold($record->name) : $record->name;

        return $record->kind->value.':'.$name;
    }
}

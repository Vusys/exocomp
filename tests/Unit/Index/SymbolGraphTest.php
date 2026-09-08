<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Index;

use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Index\Index;
use Vusys\Exocomp\Index\MemberOrigin;
use Vusys\Exocomp\Index\ResolvedMember;
use Vusys\Exocomp\Index\SymbolGraph;
use Vusys\Exocomp\Index\SymbolRecord;
use Vusys\Exocomp\Project\AutoloadMap;
use Vusys\Exocomp\Project\Project;
use Vusys\Exocomp\Symbol\SymbolKind;
use Vusys\Exocomp\Tests\Support\Cli;

/**
 * The inheritance graph, which is entirely syntactic.
 *
 * Working out that a method arrives through a trait, or that a class satisfies an interface it
 * never names, needs the shape of the hierarchy and no type information at all — which is why
 * this lands a phase before inference despite answering a large share of what inference is
 * usually assumed to be needed for.
 */
final class SymbolGraphTest extends TestCase
{
    public function test_subclasses_are_found_transitively(): void
    {
        $this->assertSame(['Fixtures\TierA\Baseline'], $this->names($this->graph()->subclasses('Fixtures\TierA\AbstractBase', true)));
    }

    /**
     * The case a naive implementation gets wrong. `Baseline` implements `Contract`, and
     * `Contract` extends `Describable` — so `Baseline` is a `Describable` without the string
     * "Describable" appearing anywhere near it. Following only one edge kind drops it.
     */
    public function test_implementors_are_found_through_an_intermediate_interface(): void
    {
        $names = $this->names($this->graph()->implementors('Fixtures\TierA\Describable', true));

        $this->assertContains('Fixtures\TierA\Contract', $names);
        $this->assertContains('Fixtures\TierA\Baseline', $names);
        $this->assertContains('Fixtures\TierB\Relative', $names);
        $this->assertContains('Fixtures\TierA\Colour', $names);
    }

    public function test_direct_implementors_exclude_the_indirect_ones(): void
    {
        $this->assertSame(['Fixtures\TierA\Colour'], $this->names($this->graph()->implementors('Fixtures\TierA\Describable', false)));
    }

    public function test_trait_users_are_found(): void
    {
        $this->assertSame(['Fixtures\TierA\Baseline'], $this->names($this->graph()->traitUsers('Fixtures\TierA\Loggable', true)));
    }

    public function test_a_class_that_is_not_there_yields_nothing_rather_than_failing(): void
    {
        $this->assertSame([], $this->graph()->subclasses('Fixtures\Nope', true));
        $this->assertNull($this->graph()->classLike('Fixtures\Nope'));
    }

    public function test_members_are_resolved_through_traits_parents_and_interfaces(): void
    {
        $resolved = $this->graph()->members('Fixtures\TierA\Baseline');
        $origins = [];

        foreach ($resolved['members'] as $member) {
            $origins[$member->record->name] = $member->originLabel();
        }

        $this->assertSame('own', $origins['greet']);
        $this->assertSame('from trait Fixtures\TierA\Loggable', $origins['log']);
        $this->assertSame('inherited from Fixtures\TierA\AbstractBase', $origins['describe']);
        $this->assertSame('required by Fixtures\TierA\Contract', $origins['VERSION']);
    }

    /**
     * `Baseline::greet()` and `AbstractBase::greet()` are the same member, and the class's own
     * declaration is the one that would run. Listing both would misreport the API; listing the
     * parent's would misreport it more.
     */
    public function test_an_overriding_declaration_displaces_the_one_it_overrides(): void
    {
        $greets = array_values(array_filter(
            $this->graph()->members('Fixtures\TierA\Baseline')['members'],
            static fn (ResolvedMember $m): bool => $m->record->name === 'greet',
        ));

        $this->assertCount(1, $greets);
        $this->assertSame(MemberOrigin::Own, $greets[0]->origin);
    }

    public function test_own_members_can_be_asked_for_without_the_hierarchy(): void
    {
        $resolved = $this->graph()->members('Fixtures\TierA\Baseline', includeInherited: false);

        foreach ($resolved['members'] as $member) {
            $this->assertSame(MemberOrigin::Own, $member->origin);
        }
    }

    public function test_an_ancestor_outside_the_index_is_reported_rather_than_ignored(): void
    {
        // `Relative` extends a class that is indexed and implements one that is; but a class
        // extending something un-indexed must say so, or its short member list reads as its
        // whole API.
        $resolved = $this->graph()->members('Fixtures\TierB\Uninformed');

        $this->assertNotSame([], $resolved['unreachable']);
        $this->assertSame('Vendor\Absent\BaseThing', $resolved['unreachable'][0]->fqn);
        $this->assertStringContainsString('not in the index', $resolved['unreachable'][0]->reason);
    }

    public function test_overrides_reach_up_and_down_the_hierarchy(): void
    {
        $owners = array_map(
            static fn (ResolvedMember $m): ?string => $m->record->owner,
            $this->graph()->overrides('Fixtures\TierA\AbstractBase', 'greet'),
        );

        $this->assertContains('Fixtures\TierA\Baseline', $owners);
    }

    public function test_a_name_lookup_folds_case_for_classes_and_methods(): void
    {
        $this->assertNotSame([], $this->graph()->byName('BASELINE', SymbolKind::ClassType));
        $this->assertNotSame([], $this->graph()->byName('GREET', SymbolKind::Method));
    }

    public function test_a_name_lookup_does_not_fold_case_for_properties(): void
    {
        $lower = $this->graph()->byName('value', SymbolKind::Property);
        $upper = $this->graph()->byName('Value', SymbolKind::Property);

        $this->assertNotSame([], $lower);
        $this->assertNotSame([], $upper);
        $this->assertNotSame($lower[0]->line, $upper[0]->line);
    }

    /**
     * @param  list<SymbolRecord>  $records
     * @return list<string>
     */
    private function names(array $records): array
    {
        return array_map(static fn (SymbolRecord $record): string => $record->fqn, $records);
    }

    private function graph(): SymbolGraph
    {
        $project = Project::at(Cli::corpusRoot());
        $index = Index::open($project, AutoloadMap::forProject($project));
        $index->sync();

        return $index->graph(validate: false);
    }
}

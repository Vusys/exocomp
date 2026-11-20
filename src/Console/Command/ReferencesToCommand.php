<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Console\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Vusys\Exocomp\Console\CommandContext;
use Vusys\Exocomp\Console\IndexedCommand;
use Vusys\Exocomp\Index\ResolvedMember;
use Vusys\Exocomp\Index\SymbolGraph;
use Vusys\Exocomp\Inference\EloquentChain;
use Vusys\Exocomp\Inference\Reference;
use Vusys\Exocomp\Inference\ReferenceFinder;
use Vusys\Exocomp\Output\Address;
use Vusys\Exocomp\Output\Result;
use Vusys\Exocomp\Output\Row;
use Vusys\Exocomp\Output\Section;
use Vusys\Exocomp\Symbol\Confidence;
use Vusys\Exocomp\Symbol\SymbolRef;

/**
 * Every reference to a symbol, with an honest account of what is missing.
 *
 * This is the pivot capability, and the whole engine is arranged around getting it right rather
 * than getting it complete. A same-named method on an unrelated class is excluded — that is
 * what separates this from `sed`. A same-named method on a subclass is included — that is what
 * stops a rename silently breaking polymorphism. And everything whose receiver cannot be
 * established is listed in the trailing block with the reason, because a reference list that
 * quietly omitted the hard cases would be the most dangerous output this tool could produce.
 */
#[AsCommand(name: 'references-to', description: 'Find every reference to a class or member, grouped by how certain each is')]
final class ReferencesToCommand extends IndexedCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->addTimeLimitOption();
        $this->addUnresolvedOption();

        $this
            ->addArgument('symbol', InputArgument::REQUIRED, 'A class or member address, e.g. "App\Models\Order::save"')
            ->addOption('strings', null, InputOption::VALUE_NONE, 'Also search string literals across the corpus — slower, and every hit is textual')
            ->addOption('resolved-only', null, InputOption::VALUE_NONE, 'Emit only the references a rename may rewrite mechanically — the resolved ones')
            ->addOption('family', null, InputOption::VALUE_NONE, 'For a member that overrides or implements another, answer for the whole hierarchy: the root declaration, every override and implementation, and the calls through any of their types — the set a rename has to change together')
            ->setHelp(<<<'HELP'
            Results are grouped by confidence:

              resolved   the receiver's type is declared, and it is this class or one related to it
              inferred   established from a docblock, or from a receiver typed as a base class
              textual    the name matches but the receiver could not be established

            Only `resolved` may safely be rewritten mechanically. The rest is handed back for
            you to judge, which is the point: a rename with 98% recall is not 98% good.

            Exit codes: 0 references found, 1 none found, 2 error, 3 none found but some candidates
            were unresolved or some files were not searched.
            HELP);
    }

    protected function answer(InputInterface $input, CommandContext $context): Result
    {
        $notes = [];
        $graph = $this->graph($input, $context, $notes);

        if (! $this->boolOption($input, 'no-sync')) {
            $context->index()->freshness($this->boolOption($input, 'vendor'))->full();
        }

        $target = $this->searchTarget($this->knownAddress(SymbolRef::parse($this->stringArgument($input, 'symbol') ?? ''), $graph, $context), $graph, $notes);
        $finder = new ReferenceFinder($context->project, $context->index(), locator: $context->locator);
        $roots = $target->hasMember() ? $this->roots($graph, $target) : [];
        $family = $this->boolOption($input, 'family') && $roots !== [];
        $searched = $family ? $roots : [$target];
        $references = [];

        // The references of a hierarchy are those of its root or roots: they already take in
        // every override's declaration and the calls through every type below.
        foreach ($searched as $root) {
            foreach ($finder->find($root, $this->boolOption($input, 'strings'), $this->searchDeadline($input), $this->searchProgress((string) $root->member)) as $reference) {
                $references[$reference->path.':'.$reference->line.':'.$reference->column.':'.$reference->kind] ??= $reference;
            }
        }

        $references = array_values($references);

        if ($family) {
            $notes[] = sprintf('the family of %s: %s and everything that overrides or implements %s', (string) $target, implode(', ', array_map(strval(...), $roots)), count($roots) === 1 ? 'it' : 'them');
        } elseif ($roots !== []) {
            $notes[] = sprintf(
                '%s overrides or implements %s; renaming it alone breaks the hierarchy — --family lists what has to change together',
                (string) $target,
                implode(' and ', array_map(strval(...), $roots)),
            );
        }

        // `--resolved-only` means the set a rename may rewrite mechanically, and that is
        // `resolved` alone. It used to drop only the textual group and print the inferred one,
        // which is the set the write side must *not* touch: an inference is worth reporting and
        // never worth rewriting on. Nothing showed it up until this work moved records out of
        // "unresolved" and into "inferred", where the flag was quietly emitting them.
        $rewritableOnly = $this->boolOption($input, 'resolved-only');

        $certain = array_values(array_filter($references, static fn (Reference $r): bool => $r->confidence === Confidence::Resolved));
        $likely = $rewritableOnly
            ? []
            : array_values(array_filter($references, static fn (Reference $r): bool => $r->confidence === Confidence::Inferred));
        $unsure = $rewritableOnly
            ? []
            : array_values(array_filter($references, static fn (Reference $r): bool => $r->confidence === Confidence::Textual));

        $width = Address::width(array_map(
            static fn (Reference $r): string => Address::of($r->path, $r->line, $r->column),
            $references,
        ));

        [$keptCertain, $elided] = $context->budget->apply($certain);

        $sections = [new Section('', array_map(fn (Reference $r): Row => $this->row($r, $width), $keptCertain))];

        if ($likely !== []) {
            $sections[] = new Section('inferred', array_map(fn (Reference $r): Row => $this->row($r, $width, true), $likely));
        }

        [$incomplete, $incompleteNotes] = $this->incompleteSearch($finder->unsearched(), $input);
        [$unresolved, $groups] = $this->unresolvedBlock(array_map(fn (Reference $r): Row => $this->row($r, $width, true), $unsure), $input);
        $excluded = $finder->excluded();

        return new Result(
            header: sprintf(
                '%d reference%s to %s  (%d resolved, %d inferred, %d unresolved%s)',
                count($references),
                count($references) === 1 ? '' : 's',
                (string) $target,
                count($certain),
                count($likely),
                count($references) - count($certain) - count($likely),
                $excluded === 0 ? '' : sprintf(', %d excluded', $excluded),
            ).$incomplete,
            sections: $sections,
            unresolvedTitle: 'unresolved',
            unresolved: $unresolved,
            elided: $elided,
            notes: [
                ...$notes,
                ...$finder->notes(),
                ...($excluded === 0 ? [] : [EloquentChain::explanation($excluded, $target->owner)]),
                ...$incompleteNotes,
                ...$context->notes(),
            ],
            found: $certain !== [] || $likely !== [],
            unresolvedGroups: $groups,
            // Matches of a member declared nowhere are names, not candidates: that none is clean.
            complete: ($unsure === [] || $finder->searchedForNothing()) && $finder->unsearched() === 0,
        );
    }

    /**
     * The topmost declarations of the target member above it: what it overrides or implements,
     * with nothing above them declaring it in turn. Empty when the target is itself the root.
     *
     * @return list<SymbolRef>
     */
    private function roots(SymbolGraph $graph, SymbolRef $target): array
    {
        $member = (string) $target->member;
        $roots = [];

        foreach ($graph->overrides($target->owner, $member) as $declaration) {
            $owner = $declaration->record->owner;

            if ($declaration->isOverridden || $owner === null) {
                continue;
            }

            $above = array_filter($graph->overrides($owner, $member), static fn (ResolvedMember $other): bool => ! $other->isOverridden);

            if ($above === []) {
                $roots[strtolower($owner)] = SymbolRef::parse($owner.'::'.$member);
            }
        }

        return array_values($roots);
    }

    private function row(Reference $reference, int $width, bool $withReason = false): Row
    {
        $address = Address::of($reference->path, $reference->line, $reference->column);

        return new Row(
            file: $reference->path,
            line: $reference->line,
            column: $reference->column,
            fqn: $reference->context,
            kind: $reference->kind,
            text: rtrim(str_pad($address, $width).'  '.$reference->snippet),
            confidence: $reference->confidence,
            note: $withReason ? $reference->reason : null,
            extra: ['context' => $reference->context, 'reason' => $reference->reason],
            carriesAddress: true,
        );
    }
}

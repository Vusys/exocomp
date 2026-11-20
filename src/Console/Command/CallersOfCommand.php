<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Console\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Vusys\Exocomp\Console\CommandContext;
use Vusys\Exocomp\Console\IndexedCommand;
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
 * Who calls this — the declarations that contain a call to it, rather than the call sites.
 *
 * The difference from `references-to` is what a reader is usually after: not "where in the file
 * does this appear" but "which parts of the system depend on it". Declaration sites and type
 * references are excluded for the same reason.
 */
#[AsCommand(name: 'callers-of', description: 'List the declarations that call a member')]
final class CallersOfCommand extends IndexedCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->addTimeLimitOption();
        $this->addUnresolvedOption();

        $this->addArgument('symbol', InputArgument::REQUIRED, 'A member address, e.g. "App\Models\Order::save"');
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

        $calls = array_values(array_filter(
            $finder->find($target, deadline: $this->searchDeadline($input), progress: $this->searchProgress((string) $target->member)),
            static fn (Reference $r): bool => str_contains($r->kind, 'call') || str_contains($r->kind, 'fetch'),
        ));

        // Grouped by the declaration each call sits in, since two calls from one method are one
        // dependency rather than two.
        $callers = [];

        foreach ($calls as $call) {
            $callers[$call->context] ??= $call;
        }

        $callers = array_values($callers);
        $width = Address::width(array_map(
            static fn (Reference $r): string => Address::of($r->path, $r->line, $r->column),
            $callers,
        ));

        $resolved = array_values(array_filter($callers, static fn (Reference $r): bool => $r->confidence !== Confidence::Textual));
        $unsure = array_values(array_filter($callers, static fn (Reference $r): bool => $r->confidence === Confidence::Textual));

        [$kept, $elided] = $context->budget->apply($resolved);

        [$incomplete, $incompleteNotes] = $this->incompleteSearch($finder->unsearched(), $input);
        [$unresolved, $groups] = $this->unresolvedBlock(array_map(fn (Reference $r): Row => $this->row($r, $width, true), $unsure), $input);
        $excluded = $finder->excluded();

        return new Result(
            header: sprintf('%d caller%s of %s', count($callers), count($callers) === 1 ? '' : 's', (string) $target)
                .($excluded === 0 ? '' : sprintf('  (%d call%s excluded)', $excluded, $excluded === 1 ? '' : 's'))
                .$incomplete,
            sections: [new Section('', array_map(fn (Reference $r): Row => $this->row($r, $width), $kept))],
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
            // Found when anything calls it; a "none" with unresolved candidates or files left
            // unsearched is not a clean none.
            found: $resolved !== [],
            unresolvedGroups: $groups,
            // Matches of a member declared nowhere are names, not candidates: that none is clean.
            complete: ($unsure === [] || $finder->searchedForNothing()) && $finder->unsearched() === 0,
        );
    }

    private function row(Reference $reference, int $width, bool $withReason = false): Row
    {
        return new Row(
            file: $reference->path,
            line: $reference->line,
            column: $reference->column,
            fqn: $reference->context,
            kind: $reference->kind,
            text: rtrim(str_pad(Address::of($reference->path, $reference->line, $reference->column), $width).'  '.$reference->context),
            confidence: $reference->confidence,
            note: $withReason ? $reference->reason : null,
            extra: ['reason' => $reference->reason],
            carriesAddress: true,
        );
    }
}

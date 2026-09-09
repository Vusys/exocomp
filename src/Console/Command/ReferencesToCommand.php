<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Console\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Vusys\Exocomp\Console\CommandContext;
use Vusys\Exocomp\Console\IndexedCommand;
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

        $this
            ->addArgument('symbol', InputArgument::REQUIRED, 'A class or member address, e.g. "App\Models\Order::save"')
            ->addOption('strings', null, InputOption::VALUE_NONE, 'Also search string literals across the corpus — slower, and every hit is textual')
            ->addOption('resolved-only', null, InputOption::VALUE_NONE, 'Emit only the references the tool will stand behind')
            ->setHelp(<<<'HELP'
            Results are grouped by confidence:

              resolved   the receiver's type is declared, and it is this class or one related to it
              inferred   established from a docblock, or from a receiver typed as a base class
              textual    the name matches but the receiver could not be established

            Only `resolved` may safely be rewritten mechanically. The rest is handed back for
            you to judge, which is the point: a rename with 98% recall is not 98% good.

            Exit codes: 0 references found, 1 none found, 2 error.
            HELP);
    }

    protected function answer(InputInterface $input, CommandContext $context): Result
    {
        $notes = [];
        $this->graph($input, $context, $notes);

        if (! $this->boolOption($input, 'no-sync')) {
            $context->index()->freshness($this->boolOption($input, 'vendor'))->full();
        }

        $target = SymbolRef::parse($this->stringArgument($input, 'symbol') ?? '');
        $finder = new ReferenceFinder($context->project, $context->index(), locator: $context->locator);
        $references = $finder->find($target, $this->boolOption($input, 'strings'));

        $certain = array_values(array_filter($references, static fn (Reference $r): bool => $r->confidence === Confidence::Resolved));
        $likely = array_values(array_filter($references, static fn (Reference $r): bool => $r->confidence === Confidence::Inferred));
        $unsure = $this->boolOption($input, 'resolved-only')
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

        return new Result(
            header: sprintf(
                '%d reference%s to %s  (%d resolved, %d inferred, %d unresolved)',
                count($references),
                count($references) === 1 ? '' : 's',
                (string) $target,
                count($certain),
                count($likely),
                count($references) - count($certain) - count($likely),
            ),
            sections: $sections,
            unresolvedTitle: 'unresolved',
            unresolved: array_map(fn (Reference $r): Row => $this->row($r, $width, true), $unsure),
            elided: $elided,
            notes: [...$notes, ...$finder->notes(), ...$context->notes()],
        );
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

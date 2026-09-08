<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Console;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Vusys\Exocomp\Index\Index;
use Vusys\Exocomp\Index\SymbolGraph;
use Vusys\Exocomp\Index\SymbolRecord;
use Vusys\Exocomp\Output\Address;
use Vusys\Exocomp\Output\Row;
use Vusys\Exocomp\Symbol\Confidence;

/**
 * The base for commands that need the whole corpus rather than one file.
 *
 * It exists mainly to hold one policy: **the index is never a precondition.** A cold cache is
 * brought up to date here, silently when it is already current and with a note when it was not,
 * rather than the command refusing and telling the caller to run a build step. That refusal is
 * the specific behaviour that stopped the obvious prior art from ever being used, and repeating
 * it would repeat the outcome.
 */
abstract class IndexedCommand extends ExocompCommand
{
    protected function configure(): void
    {
        parent::configure();

        $this->addOption('no-sync', null, InputOption::VALUE_NONE, 'Answer from the index as it stands, without checking the working tree for changes');
        $this->addOption('vendor', null, InputOption::VALUE_NONE, 'Include dependencies when the index has to be brought up to date');
    }

    /**
     * The graph, with validation attached unless the caller has opted out.
     *
     * Nothing is synchronised up front. The index is brought level with the working tree as
     * answers are read out of it, so a query costs what its answer costs rather than what the
     * corpus costs.
     *
     * @param  list<string>  $notes  filled in with anything that changed underneath
     */
    protected function graph(InputInterface $input, CommandContext $context, array &$notes): SymbolGraph
    {
        $index = $context->index();
        $vendor = $this->boolOption($input, 'vendor');

        if ($this->boolOption($input, 'no-sync')) {
            $notes[] = 'answered from the index as it stands, without checking the working tree (--no-sync)';

            return $index->graph(validate: false);
        }

        return $index->graph(validate: true, includeVendor: $vendor);
    }

    /**
     * A record rendered for a result set that spans files: address first, then what it is.
     *
     * @param  array<string, string|int|bool|null>  $extra
     */
    protected function located(SymbolRecord $record, int $addressWidth, string $suffix = '', array $extra = []): Row
    {
        $address = Address::of($record->path, $record->line, $record->column);

        return new Row(
            file: $record->path,
            line: $record->line,
            column: $record->column,
            fqn: $record->fqn,
            kind: $record->kind->value,
            text: rtrim(str_pad($address, $addressWidth).'  '.$record->fqn.$suffix),
            confidence: Confidence::Resolved,
            extra: ['signature' => $record->signature] + $extra,
            carriesAddress: true,
        );
    }

    /**
     * @param  list<SymbolRecord>  $records
     */
    protected function addressWidth(array $records): int
    {
        return Address::width(array_map(
            static fn (SymbolRecord $record): string => Address::of($record->path, $record->line, $record->column),
            $records,
        ));
    }

    protected function indexOf(CommandContext $context): Index
    {
        return $context->index();
    }
}

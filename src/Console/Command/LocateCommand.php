<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Console\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Vusys\Exocomp\Console\CommandContext;
use Vusys\Exocomp\Console\IndexedCommand;
use Vusys\Exocomp\Index\SymbolRecord;
use Vusys\Exocomp\Output\Result;
use Vusys\Exocomp\Output\Row;
use Vusys\Exocomp\Output\Section;
use Vusys\Exocomp\Project\SymbolNotFound;
use Vusys\Exocomp\Symbol\SymbolKind;

/**
 * Short name to fully-qualified name to file.
 *
 * This replaces 228 `find -name '*Foo*.php'` calls and 317 `ls | grep` calls. Those work only
 * when a class is in a file named after it — which measured 99.9% true for application code and
 * 95% for tests, so they mostly work and occasionally lie. This does not depend on the
 * convention at all, and it answers with the fully-qualified name, which is the thing every
 * other command wants as input.
 */
#[AsCommand(name: 'locate', description: 'Find where a name is declared, by short name or fully-qualified name')]
final class LocateCommand extends IndexedCommand
{
    protected function configure(): void
    {
        parent::configure();

        $this
            ->addArgument('name', InputArgument::REQUIRED, 'A short name (Order) or a fully-qualified one (App\Models\Order)')
            ->addOption('kind', null, InputOption::VALUE_REQUIRED, 'Restrict to one kind: class, interface, trait, enum, function, method, property, constant, enum-case');
    }

    protected function answer(InputInterface $input, CommandContext $context): Result
    {
        $notes = [];
        $graph = $this->graph($input, $context, $notes);
        $name = trim($this->stringArgument($input, 'name') ?? '', '\\');
        $kind = $this->kind($input);

        $records = $graph->byName($name, $kind);

        if ($kind instanceof SymbolKind) {
            $records = array_values(array_filter(
                $records,
                static fn (SymbolRecord $record): bool => $record->kind === $kind,
            ));
        }

        if ($records === []) {
            throw new SymbolNotFound($name, sprintf(
                'nothing named "%s" is declared in the indexed source roots%s',
                $name,
                $context->index()->isEmpty() ? ' (the index is empty)' : '',
            ));
        }

        $width = $this->addressWidth($records);
        [$kept, $elided] = $context->budget->apply(array_map(
            fn (SymbolRecord $record): Row => $this->located($record, $width, '   '.$record->kind->value),
            $records,
        ));

        return new Result(
            header: sprintf('%d declaration%s of "%s"', count($records), count($records) === 1 ? '' : 's', $name),
            sections: [new Section('', $kept)],
            elided: $elided,
            notes: [...$notes, ...$context->notes()],
        );
    }

    private function kind(InputInterface $input): ?SymbolKind
    {
        $value = $this->stringOption($input, 'kind');

        if ($value === null) {
            return null;
        }

        return SymbolKind::tryFrom($value) ?? throw new \InvalidArgumentException(sprintf(
            'unknown --kind "%s" (known: %s)',
            $value,
            implode(', ', array_column(SymbolKind::cases(), 'value')),
        ));
    }
}

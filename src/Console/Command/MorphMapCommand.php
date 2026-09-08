<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Console\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Vusys\Exocomp\Console\CommandContext;
use Vusys\Exocomp\Console\IndexedFrameworkCommand;
use Vusys\Exocomp\Framework\Laravel\Binding;
use Vusys\Exocomp\Output\Address;
use Vusys\Exocomp\Output\Result;
use Vusys\Exocomp\Output\Row;
use Vusys\Exocomp\Output\Section;
use Vusys\Exocomp\Project\SymbolNotFound;

/**
 * The morph map: the short string a polymorphic relation stores, and the class it stands for.
 *
 * This is needed twice over. A `morphTo` relation names no class at all, so the map is the only
 * thing that says what the far end can be — and the map's keys are written into the *database*,
 * in `*_type` columns, which makes it the single most dangerous thing to be unaware of when
 * renaming a class. A rename that updates the code and says nothing about `commentable_type`
 * has made the application worse, and the write side of this tool will need exactly this
 * command to warn about it.
 */
#[AsCommand(name: 'morph-map', description: 'List the polymorphic morph map: stored alias to class')]
final class MorphMapCommand extends IndexedFrameworkCommand
{
    protected function configure(): void
    {
        parent::configure();

        $this->addArgument('name', InputArgument::OPTIONAL, 'An alias or a class name to look up in either direction');
    }

    protected function answer(InputInterface $input, CommandContext $context): Result
    {
        $notes = [];
        $graph = $this->graph($input, $context, $notes);
        $reader = $this->container($input, $context, $graph);
        $needle = $this->stringArgument($input, 'name');

        $entries = $reader->morphMap();

        if ($needle !== null && $needle !== '') {
            $entries = array_values(array_filter($entries, static fn (Binding $entry): bool => stripos($entry->abstract, $needle) !== false
                || ($entry->concrete !== null && stripos($entry->concrete, $needle) !== false)));
        }

        if ($entries === []) {
            throw new SymbolNotFound($needle ?? 'morph map', sprintf(
                '%d service provider%s were read and no morph map %s',
                $reader->providersRead(),
                $reader->providersRead() === 1 ? '' : 's',
                $needle === null ? 'is registered' : sprintf('entry matches "%s"', $needle),
            ));
        }

        $width = Address::width(array_map(
            static fn (Binding $entry): string => Address::of($entry->path, $entry->line),
            $entries,
        ));

        [$kept, $elided] = $context->budget->apply(array_map(
            static fn (Binding $entry): Row => new Row(
                file: $entry->path,
                line: $entry->line,
                column: null,
                fqn: $entry->concrete,
                kind: 'morph',
                text: rtrim(sprintf(
                    '%s  %-32s %s',
                    str_pad(Address::of($entry->path, $entry->line), $width),
                    $entry->abstract,
                    $entry->concrete ?? '?',
                )),
                confidence: $entry->confidence,
                note: $entry->note,
                extra: ['alias' => $entry->abstract, 'class' => $entry->concrete],
                carriesAddress: true,
            ),
            $entries,
        ));

        return new Result(
            header: sprintf('%d morph map entr%s', count($entries), count($entries) === 1 ? 'y' : 'ies'),
            sections: [new Section('', $kept)],
            elided: $elided,
            notes: [
                ...$notes,
                'these aliases are stored in the database, in the *_type column of every polymorphic relation',
                ...$reader->notes(),
                ...$context->notes(),
            ],
        );
    }
}

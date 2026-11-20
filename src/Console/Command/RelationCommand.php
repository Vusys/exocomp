<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Console\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Vusys\Exocomp\Console\CommandContext;
use Vusys\Exocomp\Console\IndexedCommand;
use Vusys\Exocomp\Index\SymbolGraph;
use Vusys\Exocomp\Index\SymbolRecord;
use Vusys\Exocomp\Output\Result;
use Vusys\Exocomp\Output\Row;
use Vusys\Exocomp\Output\Section;
use Vusys\Exocomp\Project\SymbolNotFound;
use Vusys\Exocomp\Symbol\SymbolKind;
use Vusys\Exocomp\Symbol\SymbolRef;

/**
 * The shared body of the three inheritance queries.
 *
 * All of this is syntactic. Working out who extends a class needs the `extends` clause and
 * nothing else — no types, no inference — which is why the inheritance graph lands a whole
 * phase before the machinery usually assumed to be its prerequisite.
 */
abstract class RelationCommand extends IndexedCommand
{
    protected function configure(): void
    {
        parent::configure();

        $this
            ->addArgument('name', InputArgument::REQUIRED, 'The class, interface or trait to look for users of')
            ->addOption('direct', null, InputOption::VALUE_NONE, 'Only immediate relations, not the whole subtree');
    }

    /**
     * @return list<SymbolRecord>
     */
    abstract protected function relatives(SymbolGraph $graph, string $fqn, bool $recursive): array;

    abstract protected function noun(int $count): string;

    /**
     * The kind the subject has to be for the question to make sense, with the command that
     * answers the question for the other kinds; null when any class-like will do.
     *
     * @return ?array{SymbolKind, string}
     */
    protected function expects(): ?array
    {
        return null;
    }

    protected function answer(InputInterface $input, CommandContext $context): Result
    {
        $notes = [];
        $graph = $this->graph($input, $context, $notes);
        $ref = SymbolRef::parse($this->stringArgument($input, 'name') ?? '');
        $subject = $graph->namedClassLike($ref->owner);

        // Resolving the subject first means "no such class" and "no subclasses" are different
        // answers. Conflating them would let a typo read as a clean bill of health.
        //
        // The index is not the only place a class can be, though. "Everything that extends
        // `Illuminate\Database\Eloquent\Model`" is one of the most useful questions there is,
        // and its subject lives in `vendor/`, which is not indexed — so a subject the index does
        // not know is looked up through Composer's autoload map before it is refused. The edges
        // themselves were never the problem: they are recorded by the files that declare the
        // children, so they exist whether or not the parent does.
        $fqn = $subject instanceof SymbolRecord ? $subject->fqn : $this->outsideTheIndex($ref, $context, $notes);

        // "0 implementors" of a class, or "0 users" of something that is not a trait, read as
        // an answer when the question could not have one.
        $expected = $this->expects();

        if ($expected !== null && $subject instanceof SymbolRecord && $subject->kind !== $expected[0]) {
            throw new SymbolNotFound($ref->owner, sprintf(
                '%s is %s %s, not %s %s — %s',
                $subject->fqn,
                $subject->kind === SymbolKind::InterfaceType ? 'an' : 'a',
                $subject->kind->value,
                $expected[0] === SymbolKind::InterfaceType ? 'an' : 'a',
                $expected[0]->value,
                sprintf($expected[1], "'".$subject->fqn."'"),
            ));
        }

        $records = $this->relatives($graph, $fqn, ! $this->boolOption($input, 'direct'));
        $width = $this->addressWidth($records);

        [$kept, $elided] = $context->budget->apply(array_map(
            fn (SymbolRecord $record): Row => $this->located($record, $width, '   '.$record->kind->value),
            $records,
        ));

        return new Result(
            header: sprintf('%d %s of %s', count($records), $this->noun(count($records)), $fqn).$this->breakdown($records),
            sections: [new Section('', $kept)],
            elided: $elided,
            notes: [...$notes, ...$context->notes()],
        );
    }

    /**
     * What else the count holds besides classes. An interface extending the subject is listed,
     * because its implementors implement the subject too, but it implements nothing itself, and
     * a bare "2 implementors" overstated the concrete classes.
     *
     * @param  list<SymbolRecord>  $records
     */
    protected function breakdown(array $records): string
    {
        $interfaces = count(array_filter($records, static fn (SymbolRecord $record): bool => $record->kind === SymbolKind::InterfaceType));

        return $interfaces === 0 || ($this->expects()[0] ?? null) !== SymbolKind::InterfaceType
            ? ''
            : sprintf('  (%d of them %s that extend%s it)', $interfaces, $interfaces === 1 ? 'an interface' : 'interfaces', $interfaces === 1 ? 's' : '');
    }

    /**
     * @param  list<string>  $notes
     */
    private function outsideTheIndex(SymbolRef $ref, CommandContext $context, array &$notes): string
    {
        try {
            $located = $context->locator->locateOwner($ref);
        } catch (SymbolNotFound) {
            throw new SymbolNotFound($ref->owner, sprintf(
                '"%s" is not a class, interface or trait in the index, and Composer cannot place it either — check the name with: exocomp locate %s',
                $ref->owner,
                $this->shortName($ref->owner),
            ));
        }

        $notes[] = sprintf(
            '%s is outside the indexed source roots; it was placed at %s through Composer\'s autoload map',
            $located->declaration->fqn,
            $located->analysis->file->displayPath,
        );

        return $located->declaration->fqn;
    }

    private function shortName(string $fqn): string
    {
        $position = strrpos($fqn, '\\');

        return $position === false ? $fqn : substr($fqn, $position + 1);
    }
}

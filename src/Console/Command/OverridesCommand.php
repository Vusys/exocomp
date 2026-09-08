<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Console\Command;

use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Vusys\Exocomp\Console\CommandContext;
use Vusys\Exocomp\Console\IndexedCommand;
use Vusys\Exocomp\Index\ResolvedMember;
use Vusys\Exocomp\Index\SymbolGraph;
use Vusys\Exocomp\Index\SymbolRecord;
use Vusys\Exocomp\Output\Address;
use Vusys\Exocomp\Output\Result;
use Vusys\Exocomp\Output\Row;
use Vusys\Exocomp\Output\Section;
use Vusys\Exocomp\Project\SymbolNotFound;
use Vusys\Exocomp\Symbol\SymbolRef;

/**
 * Every declaration of one member across a hierarchy.
 *
 * The question behind it is almost always "if I change this, what else is this". A same-named
 * method on an unrelated class is a different method and must not appear here; a same-named
 * method on a subclass is the same method and must. That distinction is exactly what separates
 * this from a search for the name, and it is the one the write side will later depend on.
 */
#[AsCommand(name: 'overrides', description: 'Show every declaration of a member across its hierarchy, up and down')]
final class OverridesCommand extends IndexedCommand
{
    protected function configure(): void
    {
        parent::configure();

        $this->addArgument('symbol', InputArgument::REQUIRED, 'A member address, e.g. "App\Models\Order::save"');
    }

    protected function answer(InputInterface $input, CommandContext $context): Result
    {
        $notes = [];
        $graph = $this->graph($input, $context, $notes);
        $ref = SymbolRef::parse($this->stringArgument($input, 'symbol') ?? '');

        if (! $ref->hasMember()) {
            throw new InvalidArgumentException('give a member address, e.g. "App\Models\Order::save"');
        }

        $owner = $graph->namedClassLike($ref->owner);

        if (! $owner instanceof SymbolRecord) {
            throw new SymbolNotFound((string) $ref, sprintf('"%s" is not a class, interface or trait in the index', $ref->owner));
        }

        $member = (string) $ref->member;
        $here = $this->declaredHere($graph, $owner->fqn, $member);
        $elsewhere = $graph->overrides($owner->fqn, $member);

        if ($here === [] && $elsewhere === []) {
            throw new SymbolNotFound((string) $ref, sprintf(
                '"%s" is not declared on %s or anywhere in its hierarchy',
                $member,
                $owner->fqn,
            ));
        }

        $all = [...$here, ...$elsewhere];
        $width = Address::width(array_map(
            static fn (ResolvedMember $m): string => Address::of($m->record->path, $m->record->line, $m->record->column),
            $all,
        ));

        [$kept, $elided] = $context->budget->apply(array_map(
            fn (ResolvedMember $m): Row => $this->row($m, $width, $owner->fqn),
            $all,
        ));

        return new Result(
            header: sprintf('%d declaration%s of "%s" around %s', count($all), count($all) === 1 ? '' : 's', $member, $owner->fqn),
            sections: [new Section('', $kept)],
            elided: $elided,
            notes: [...$notes, ...$context->notes()],
        );
    }

    /**
     * @return list<ResolvedMember>
     */
    private function declaredHere(SymbolGraph $graph, string $ownerFqn, string $member): array
    {
        $found = [];

        foreach ($graph->members($ownerFqn, includeInherited: false)['members'] as $resolved) {
            if (strcasecmp($resolved->record->name, $member) === 0 || $resolved->record->name === $member) {
                $found[] = $resolved;
            }
        }

        return $found;
    }

    private function row(ResolvedMember $member, int $width, string $subject): Row
    {
        $record = $member->record;
        $relation = $record->owner === null || strcasecmp($record->owner, $subject) === 0
            ? 'declared here'
            : $member->originLabel();

        return new Row(
            file: $record->path,
            line: $record->line,
            column: $record->column,
            fqn: $record->fqn,
            kind: $record->kind->value,
            text: rtrim(str_pad(Address::of($record->path, $record->line, $record->column), $width).'  '.$record->signature.'   '.$relation),
            extra: ['relation' => $relation, 'owner' => $record->owner],
            carriesAddress: true,
        );
    }
}

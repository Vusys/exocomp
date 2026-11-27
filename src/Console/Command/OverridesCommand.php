<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Console\Command;

use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Vusys\Exocomp\Analysis\Declaration;
use Vusys\Exocomp\Console\CommandContext;
use Vusys\Exocomp\Console\IndexedCommand;
use Vusys\Exocomp\Index\MemberOrigin;
use Vusys\Exocomp\Index\ResolvedMember;
use Vusys\Exocomp\Index\SymbolGraph;
use Vusys\Exocomp\Index\SymbolRecord;
use Vusys\Exocomp\Output\Address;
use Vusys\Exocomp\Output\Result;
use Vusys\Exocomp\Output\Row;
use Vusys\Exocomp\Output\Section;
use Vusys\Exocomp\Project\ClassSource;
use Vusys\Exocomp\Project\LoadedClass;
use Vusys\Exocomp\Project\SymbolNotFound;
use Vusys\Exocomp\Symbol\SymbolKind;
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

        // A class the index does not hold, `Illuminate\Database\Eloquent\Model`, is placed as
        // `show` places it; its subclasses in the corpus are still found through the index.
        $ref = $this->knownAddress($ref, $graph, $context);
        $ownerFqn = $graph->classLike($ref->owner)->fqn ?? $ref->owner;
        $source = new ClassSource($context->project, $graph, $context->locator);

        $member = (string) $ref->member;
        $this->subjectIsInterface = $graph->classLike($ownerFqn)?->kind === SymbolKind::InterfaceType;
        $here = $graph->classLike($ownerFqn) instanceof SymbolRecord
            ? $this->declaredHere($graph, $ownerFqn, $member)
            : $this->readFromSource($source, [$ownerFqn], $member, MemberOrigin::Own);
        $elsewhere = [...$graph->overrides($ownerFqn, $member), ...$this->ancestorsBeyondTheIndex($graph, $source, $ownerFqn, $member)];

        if ($here === [] && $elsewhere === []) {
            throw new SymbolNotFound((string) $ref, sprintf(
                '"%s" is not declared on %s or anywhere in its hierarchy',
                $member,
                $ownerFqn,
            ));
        }

        $all = [...$here, ...$elsewhere];
        $width = Address::width(array_map(
            static fn (ResolvedMember $m): string => Address::of($m->record->path, $m->record->line, $m->record->column),
            $all,
        ));

        [$kept, $elided] = $context->budget->apply(array_map(
            fn (ResolvedMember $m): Row => $this->row($m, $width, $ownerFqn),
            $all,
        ));

        return new Result(
            header: sprintf('%d declaration%s of "%s" around %s', count($all), count($all) === 1 ? '' : 's', $member, $ownerFqn),
            sections: [new Section('', $kept)],
            elided: $elided,
            notes: [...$notes, ...$context->notes()],
            // The declaration itself is always listed; the answer is whatever else there is.
            found: $elsewhere !== [],
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

    /**
     * The ancestors the index does not hold, read from source: a model's `save()` is declared in
     * `vendor/`, and "not declared anywhere in its hierarchy" was false.
     *
     * @return list<ResolvedMember>
     */
    private function ancestorsBeyondTheIndex(SymbolGraph $graph, ClassSource $source, string $ownerFqn, string $member): array
    {
        $outside = [];

        foreach (array_slice($source->withAncestors($ownerFqn), 1) as $class) {
            if (! $graph->classLike($class->fqn) instanceof SymbolRecord) {
                $outside[] = $class->fqn;
            }
        }

        return $this->readFromSource($source, $outside, $member, MemberOrigin::Inherited);
    }

    /**
     * @param  list<string>  $classes
     * @return list<ResolvedMember>
     */
    private function readFromSource(ClassSource $source, array $classes, string $member, MemberOrigin $origin): array
    {
        $found = [];

        foreach ($classes as $fqn) {
            $class = $source->load($fqn);

            if (! $class instanceof LoadedClass) {
                continue;
            }

            $declaration = $class->analysis->classLike($class->fqn)?->member($member, false);

            if (! $declaration instanceof Declaration) {
                continue;
            }

            $found[] = new ResolvedMember(new SymbolRecord(
                path: $class->path,
                fqn: $class->fqn.'::'.$declaration->name,
                name: $declaration->name,
                kind: $declaration->kind,
                owner: $class->fqn,
                line: $declaration->startLine,
                endLine: $declaration->endLine,
                column: $declaration->startColumn,
                signature: $declaration->signature,
                fullSignature: $declaration->fullSignature,
                modifiers: implode(' ', $declaration->modifiers),
                isPromoted: false,
                hasBody: $declaration->hasBody,
            ), $origin, $class->fqn, false);
        }

        return $found;
    }

    private bool $subjectIsInterface = false;

    private function row(ResolvedMember $member, int $width, string $subject): Row
    {
        $record = $member->record;
        // A descendant's declaration overrides (or implements) the one asked about; it is not
        // inherited from anything.
        $relation = match (true) {
            $record->owner === null || strcasecmp($record->owner, $subject) === 0 => 'declared here',
            $member->isOverridden => sprintf('%s it, in %s', $this->subjectIsInterface ? 'implements' : 'overrides', $record->owner),
            str_starts_with($record->path, 'vendor/') => sprintf('%s, in a dependency', $member->originLabel()),
            default => $member->originLabel(),
        };

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

<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Console\Command;

use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Vusys\Exocomp\Console\CommandContext;
use Vusys\Exocomp\Console\IndexedCommand;
use Vusys\Exocomp\Index\SymbolRecord;
use Vusys\Exocomp\Output\Counted;
use Vusys\Exocomp\Output\Result;
use Vusys\Exocomp\Output\Row;
use Vusys\Exocomp\Output\Section;
use Vusys\Exocomp\Project\DeclarationScan;
use Vusys\Exocomp\Project\SymbolNotFound;
use Vusys\Exocomp\Symbol\Confidence;
use Vusys\Exocomp\Symbol\SymbolKind;
use Vusys\Exocomp\Symbol\SymbolRef;

/**
 * Short name to fully-qualified name to file.
 *
 * This replaces 228 `find -name '*Foo*.php'` calls and 317 `ls | grep` calls. Those work only
 * when a class is in a file named after it — which measured 99.9% true for application code and
 * 95% for tests, so they mostly work and occasionally lie. This does not depend on the
 * convention at all, and it answers with the fully-qualified name, which is the thing every
 * other command wants as input.
 *
 * It never returns a bare negative on the index's word alone. That was measured doing real
 * damage: on a worktree whose first index pass had not finished, `locate` reported nothing
 * named `Gateway` while `show` read `App\Models\Billing\Gateway` out of the same checkout
 * seconds later. Every other command falls through to Composer's map on a miss and this one had
 * nothing to fall through to, so a partial index became a confident, wrong "no" — in the very
 * command the guidance names as the way out of a wrong guess.
 */
#[AsCommand(name: 'locate', description: 'Find where a name is declared, by short name or fully-qualified name')]
final class LocateCommand extends IndexedCommand
{
    protected function configure(): void
    {
        parent::configure();

        $this
            ->addArgument('name', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'One or more names, short (Order) or fully-qualified (App\Models\Order)')
            ->addOption('kind', null, InputOption::VALUE_REQUIRED, 'Restrict to one kind: class, interface, trait, enum, function, method, property, constant, enum-case')
            ->setHelp(<<<'HELP'
            Several names may be asked about at once, and are reported in the order given:

              exocomp locate OrderService OrderRepository OrderPolicy

            That is the shape of the question far more often than one name is — "which of these
            exists, and where" — and asking it as one command costs one index read rather than
            three.

            A name the index does not know is looked for by reading the source roots before any
            negative is returned, so "nothing is declared with that name" means the bytes were
            read and it was not there. `--no-sync` skips that and answers from the index as it
            stands, which is faster and can be wrong.

            Exit codes: 0 every name found, 1 one or more not found, 2 error.
            HELP);
    }

    protected function answer(InputInterface $input, CommandContext $context): Result
    {
        $notes = [];
        $graph = $this->graph($input, $context, $notes);
        $names = $this->names($input);
        $kind = $this->kind($input);
        $trustIndex = $this->boolOption($input, 'no-sync');

        $found = [];
        $missing = [];
        $scanned = false;

        foreach ($names as $name) {
            $records = $graph->byName($name, $kind);

            if ($kind instanceof SymbolKind) {
                $records = array_values(array_filter(
                    $records,
                    static fn (SymbolRecord $record): bool => $record->kind === $kind,
                ));
            }

            if ($records === [] && ! $trustIndex) {
                $records = $this->readTheSource($name, $kind, $context, $notes);
                $scanned = $scanned || $records !== [];
            }

            if ($records === [] && str_contains(trim($name, '\\'), '\\')) {
                $records = $this->inADependency(trim($name, '\\'), $kind, $context, $notes);
            }

            if ($records === []) {
                $missing[] = $name;

                continue;
            }

            $found[$name] = $records;
        }

        if ($scanned) {
            $notes[] = 'the index did not hold everything answered here; it was read out of the source instead — run "exocomp index --warm" to stop paying for that';
        }

        // One name keeps the answer it has always had, so a caller that asks the old question
        // does not have to parse a new shape because the command learned to take two.
        if (count($names) === 1 && $missing === []) {
            return $this->single((string) array_key_first($found), $found[array_key_first($found)], $context, $notes);
        }

        if ($found === []) {
            throw new SymbolNotFound(
                implode(', ', $missing),
                $this->nothingFound($missing, $context, $trustIndex),
            );
        }

        return $this->several($names, $found, $missing, $context, $notes);
    }

    /**
     * @param  list<SymbolRecord>  $records
     * @param  list<string>  $notes
     */
    private function single(string $name, array $records, CommandContext $context, array $notes): Result
    {
        $width = $this->addressWidth($records);
        [$kept, $elided] = $context->budget->apply(array_map(
            fn (SymbolRecord $record): Row => $this->located($record, $width, '   '.$record->kind->value),
            $records,
        ));

        return new Result(
            header: sprintf('%s of "%s"', Counted::of(count($records), 'declaration'), $name),
            sections: [new Section('', $kept)],
            elided: $elided,
            notes: [...$notes, ...$context->notes()],
        );
    }

    /**
     * @param  list<string>  $names
     * @param  array<string, list<SymbolRecord>>  $found
     * @param  list<string>  $missing
     * @param  list<string>  $notes
     */
    private function several(array $names, array $found, array $missing, CommandContext $context, array $notes): Result
    {
        $sections = [];
        $total = 0;

        foreach ($names as $name) {
            $records = $found[$name] ?? null;

            if ($records === null) {
                continue;
            }

            $total += count($records);
            $width = $this->addressWidth($records);

            // The renderer appends the row count to a section title itself.
            $sections[] = new Section(
                $name,
                array_map(
                    fn (SymbolRecord $record): Row => $this->located($record, $width, '   '.$record->kind->value),
                    $records,
                ),
            );
        }

        return new Result(
            header: sprintf(
                '%d declaration%s across %d of %d names',
                $total,
                $total === 1 ? '' : 's',
                count($found),
                count($names),
            ),
            sections: $sections,
            unresolvedTitle: 'not declared anywhere',
            unresolved: array_map(
                static fn (string $name): Row => new Row(
                    file: '',
                    line: null,
                    column: null,
                    fqn: null,
                    kind: null,
                    text: $name,
                    confidence: Confidence::Textual,
                ),
                $missing,
            ),
            notes: [...$notes, ...$context->notes()],
            found: $missing === [],
        );
    }

    /**
     * The source roots, read for a name the index did not have.
     *
     * @param  list<string>  $notes
     * @return list<SymbolRecord>
     */
    private function readTheSource(string $name, ?SymbolKind $kind, CommandContext $context, array &$notes): array
    {
        $unparsed = [];
        $records = new DeclarationScan($context->map, $context->locator)->byShortName($name, $kind, $unparsed);

        foreach ($unparsed as $failure) {
            $notes[] = $failure;
        }

        return $records;
    }

    /**
     * A fully-qualified name placed where `show` would place it: Composer's autoload map, for a
     * class in `vendor/` that the index does not hold by default.
     *
     * @param  list<string>  $notes
     * @return list<SymbolRecord>
     */
    private function inADependency(string $fqn, ?SymbolKind $kind, CommandContext $context, array &$notes): array
    {
        try {
            $located = $context->locator->locateOwner(SymbolRef::parse($fqn), speculative: true);
        } catch (SymbolNotFound|InvalidArgumentException) {
            return [];
        }

        $declaration = $located->declaration;

        if (strcasecmp($declaration->fqn, $fqn) !== 0 || ($kind instanceof SymbolKind && $declaration->kind !== $kind)) {
            return [];
        }

        $notes[] = sprintf('%s is outside the indexed source roots; it was placed through Composer\'s autoload map', $declaration->fqn);

        return [new SymbolRecord(
            path: $located->analysis->file->displayPath,
            fqn: $declaration->fqn,
            name: $declaration->name,
            kind: $declaration->kind,
            owner: null,
            line: $declaration->startLine,
            endLine: $declaration->endLine,
            column: $declaration->startColumn,
            signature: $declaration->signature,
            fullSignature: $declaration->fullSignature,
            modifiers: implode(' ', $declaration->modifiers),
            isPromoted: false,
            hasBody: $declaration->hasBody,
        )];
    }

    /**
     * What the index actually held, said out loud.
     *
     * A negative is the one answer a caller cannot check, so it has to carry the size of the
     * thing that produced it. "Nothing named X" from an index of 6,600 files is a fact;
     * the same sentence from an index of nothing is an accident, and the two used to read
     * identically.
     *
     * @param  list<string>  $missing
     */
    private function nothingFound(array $missing, CommandContext $context, bool $trustIndex): string
    {
        $statistics = $context->index()->statistics();

        return sprintf(
            'nothing named %s is declared in the indexed source roots (index: %d file%s, %d symbol%s)%s',
            implode(' or ', array_map(static fn (string $name): string => '"'.$name.'"', $missing)),
            $statistics['files'],
            $statistics['files'] === 1 ? '' : 's',
            $statistics['symbols'],
            $statistics['symbols'] === 1 ? '' : 's',
            $trustIndex
                ? ' — the source was not read, because --no-sync was given'
                : ' — the source roots were read as well, so this is not a stale answer'
                    .($context->index()->store->holdsPathsUnder($context->project->root, 'vendor/') ? '' : '; dependencies were not searched (--vendor includes them)'),
        );
    }

    /**
     * @return list<string>
     */
    private function names(InputInterface $input): array
    {
        $names = array_values(array_filter(
            array_map(
                static fn (string $name): string => trim($name, '\\'),
                $this->stringListArgument($input, 'name'),
            ),
            static fn (string $name): bool => $name !== '',
        ));

        if ($names === []) {
            throw new InvalidArgumentException('locate wants at least one name');
        }

        return $names;
    }

    private function kind(InputInterface $input): ?SymbolKind
    {
        $value = $this->stringOption($input, 'kind');

        if ($value === null) {
            return null;
        }

        return SymbolKind::tryFrom($value) ?? throw new InvalidArgumentException(sprintf(
            'unknown --kind "%s" (known: %s)',
            $value,
            implode(', ', array_column(SymbolKind::cases(), 'value')),
        ));
    }
}

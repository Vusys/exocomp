<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Console\Command;

use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Vusys\Exocomp\Analysis\Declaration;
use Vusys\Exocomp\Console\CommandContext;
use Vusys\Exocomp\Console\IndexedCommand;
use Vusys\Exocomp\Index\ResolvedMember;
use Vusys\Exocomp\Index\SymbolGraph;
use Vusys\Exocomp\Index\SymbolRecord;
use Vusys\Exocomp\Index\UnreachableAncestor;
use Vusys\Exocomp\Inference\MemberElsewhere;
use Vusys\Exocomp\Inference\TraitAdaptations;
use Vusys\Exocomp\Output\Address;
use Vusys\Exocomp\Output\Result;
use Vusys\Exocomp\Output\Row;
use Vusys\Exocomp\Output\Section;
use Vusys\Exocomp\Project\ClassSource;
use Vusys\Exocomp\Symbol\Confidence;
use Vusys\Exocomp\Symbol\SymbolKind;
use Vusys\Exocomp\Symbol\SymbolRef;

/**
 * A class's resolved API, in one call.
 *
 * This is the answer to candidate-name guessing: 661 of the measured searches OR'd four or more
 * plausible method names together and let grep say which existed, with a guess at the body
 * length on top. The reason grep cannot do better is that most of a class's API is usually not
 * written in its file — it arrives through a parent or a trait — so the list has to come from
 * the hierarchy, and each entry has to say where it came from.
 *
 * `--own` keeps the declared-only view, and keeps it free: it answers from the file alone, with
 * no index and no freshness check.
 */
#[AsCommand(name: 'members', description: 'List a class\'s members, resolved through its parents and traits')]
final class MembersCommand extends IndexedCommand
{
    protected function configure(): void
    {
        parent::configure();

        $this
            ->addArgument('class', InputArgument::REQUIRED, 'Class, interface, trait or enum name')
            ->addOption('own', null, InputOption::VALUE_NONE, 'Only what this class declares itself, answered from the file with no index')
            ->addOption('matching', null, InputOption::VALUE_REQUIRED, 'Only members whose name matches this regular expression (case-insensitive, unanchored)')
            ->setHelp(<<<'HELP'
            `--matching` takes a regular expression, applied to the member name and nothing
            else — not the signature, not the file, not the declaring class — so it says what it
            looks like it says:

              exocomp members 'App\Models\Order' --matching 'contract|total|bot'

            It is case-insensitive and unanchored, which is what a name filter almost always
            wants; anchor it yourself with ^ and $ when it does not.

            This replaces piping the answer into `grep`, which was measured as the commonest
            thing done to this command's output and which quietly re-introduced the problem the
            output contract exists to prevent: a filtered list gives no indication of what the
            filter removed. Here the header says how many members the class has and how many
            the filter kept, so a narrow pattern reads as narrow rather than as a small class.

            Exit codes: 0 found, 1 the class has no member matching, 2 error.
            HELP);
    }

    protected function answer(InputInterface $input, CommandContext $context): Result
    {
        $ref = SymbolRef::parse($this->stringArgument($input, 'class') ?? '');
        $matching = $this->pattern($input);

        if ($this->boolOption($input, 'own')) {
            return $this->declaredOnly($ref, $context, matching: $matching);
        }

        $notes = [];
        $graph = $this->graph($input, $context, $notes);
        $subject = $graph->namedClassLike($ref->owner);

        if (! $subject instanceof SymbolRecord) {
            // A guessed namespace first: corrected, it is a class the index holds, and the
            // answer is the same as for the right name.
            $ref = $this->knownAddress($ref, $graph, $context);
            $subject = $graph->classLike($ref->owner);
        }

        if (! $subject instanceof SymbolRecord) {
            // Not in the index — a vendor class, most likely. Rather than refuse, answer what
            // the parser alone can see and say what that costs.
            $notes[] = sprintf('"%s" is not in the index, so only its own members are listed (exocomp index --warm --vendor indexes dependencies)', $ref->owner);

            return $this->declaredOnly($ref, $context, $notes, why: 'not in the index — exocomp index --warm --vendor indexes dependencies');
        }

        return $this->listing($subject, $graph, $ref, $matching, $notes, $context);
    }

    /**
     * @param  list<string>  $notes
     */
    private function listing(SymbolRecord $subject, SymbolGraph $graph, SymbolRef $ref, ?string $matching, array $notes, CommandContext $context, bool $redirected = false): Result
    {
        $resolved = $graph->members($subject->fqn);
        $total = count($resolved['members']);
        $members = array_values(array_filter(
            $resolved['members'],
            static fn (ResolvedMember $member): bool => ($matching === null || preg_match($matching, $member->record->name) === 1)
                && (! $ref->hasMember() || self::named($member->record, $ref)),
        ));

        // A member asked for by name and not found here is very often found on a trait, a
        // subclass or a sibling — and when exactly one related class declares it, that is the
        // answer rather than a second command for the caller to run.
        if ($members === [] && $ref->hasMember() && ! $redirected) {
            $elsewhere = new MemberElsewhere($graph)->find(SymbolRef::parse($subject->fqn.'::'.($ref->memberIsProperty ? '$' : '').$ref->member));
            $answer = $elsewhere['answer'];
            $declaring = $answer?->owner === null ? null : $graph->classLike($answer->owner);

            if ($elsewhere['note'] !== null) {
                $notes[] = $elsewhere['note'];
            }

            if ($declaring instanceof SymbolRecord) {
                return $this->listing($declaring, $graph, $ref, $matching, $notes, $context, redirected: true);
            }
        }

        [$kept, $elided] = $context->budget->apply($members);
        $aliases = $this->aliases($subject, $graph, $ref, $matching, $context);
        $sections = $this->group($kept);

        if ($aliases !== []) {
            $sections[] = new Section('aliased from traits', $aliases);
        }

        return new Result(
            header: $this->header($subject->path, $subject->fqn, count($members) + count($aliases), $total + count($aliases), $matching ?? ($ref->hasMember() ? '' : null), count($resolved['unreachable'])),
            sections: $sections,
            unresolvedTitle: 'not reached',
            unresolved: $this->unreachable($subject->path, $resolved['unreachable']),
            elided: $elided,
            notes: [...$notes, ...$context->notes()],
            found: $members !== [] || $aliases !== [],
        );
    }

    /**
     * The methods a `use T { hello as greet; }` adaptation gives the class: each at the trait
     * method it runs, under the name and visibility the class gives it.
     *
     * @return list<Row>
     */
    private function aliases(SymbolRecord $subject, SymbolGraph $graph, SymbolRef $ref, ?string $matching, CommandContext $context): array
    {
        $rows = [];

        foreach (new TraitAdaptations(new ClassSource($context->project, $graph, $context->locator), $graph)->aliasesOf($subject->fqn) as $alias) {
            if (($matching !== null && preg_match($matching, $alias['name']) !== 1) || ($ref->hasMember() && strcasecmp((string) $ref->member, $alias['name']) !== 0)) {
                continue;
            }

            [$trait, $method] = explode('::', $alias['target'], 2);
            $record = array_find(
                $graph->members($trait, includeInherited: false)['members'],
                static fn (ResolvedMember $member): bool => $member->record->kind === SymbolKind::Method && strcasecmp($member->record->name, $method) === 0,
            )?->record;

            if (! $record instanceof SymbolRecord) {
                continue;
            }

            $signature = (string) preg_replace('/\bfunction\s+&?'.preg_quote($record->name, '/').'\b/i', 'function '.$alias['name'], $record->signature);
            $signature = $alias['visibility'] === null ? $signature : (string) preg_replace('/\b(public|protected|private)\b/', $alias['visibility'], $signature, 1);

            $rows[] = new Row(
                file: $record->path,
                line: $record->line,
                column: $record->column,
                fqn: $subject->fqn.'::'.$alias['name'],
                kind: 'method',
                text: sprintf('%s  %s  (%s)', Address::of($record->path, $record->line, $record->column), $signature, $alias['target']),
                extra: ['origin' => 'alias', 'origin_fqn' => $alias['target'], 'promoted' => false],
                carriesAddress: true,
            );
        }

        return $rows;
    }

    /**
     * The member an address names, by PHP's rules for that kind of member: a method's name
     * folds case and nothing else's does.
     */
    private static function named(SymbolRecord $record, SymbolRef $ref): bool
    {
        if ($ref->memberIsProperty && $record->kind !== SymbolKind::Property) {
            return false;
        }

        return $record->kind === SymbolKind::Method
            ? strcasecmp($record->name, (string) $ref->member) === 0
            : $record->name === $ref->member;
    }

    /**
     * The count line, which has to say what the filter removed.
     *
     * A filtered list that reports only its own length is indistinguishable from a small class,
     * and that is precisely the silent truncation this tool's output contract exists to rule
     * out. Saying "9 of 78" makes a narrow pattern read as narrow.
     */
    private function header(string $path, string $fqn, int $shown, int $total, ?string $matching, int $unreached = 0): string
    {
        // With ancestors out of reach the total is only what could be read, and has to say so,
        // or a narrow pattern on a framework subclass reads as a small class all over again.
        $unreachable = $unreached === 0 ? '' : sprintf('; %d ancestor%s not reached', $unreached, $unreached === 1 ? '' : 's');
        $members = $unreached === 0 ? 'members' : 'reachable members';

        if ($matching === null) {
            return sprintf('%s  %s  (%d %s%s)', $path, $fqn, $total, $members, $unreachable);
        }

        return sprintf('%s  %s  (%d of %d %s matching%s)', $path, $fqn, $shown, $total, $members, $unreachable);
    }

    /**
     * The `--matching` expression, delimited and validated here so a bad pattern is a clear
     * error rather than a PHP warning and an empty list that reads as "no such member".
     */
    private function pattern(InputInterface $input): ?string
    {
        $value = $this->stringOption($input, 'matching');

        if ($value === null) {
            return null;
        }

        $pattern = '/'.str_replace('/', '\/', $value).'/i';

        if (@preg_match($pattern, '') === false) {
            throw new InvalidArgumentException(sprintf('--matching "%s" is not a valid regular expression', $value));
        }

        return $pattern;
    }

    /**
     * Grouped by where each member came from, because origin is the entire value-add over
     * reading the file. Own members first; everything else in the order PHP would resolve it.
     *
     * @param  list<ResolvedMember>  $members
     * @return list<Section>
     */
    private function group(array $members): array
    {
        $groups = [];

        foreach ($members as $member) {
            $groups[$member->originLabel()][] = $member;
        }

        $addresses = array_map(
            static fn (ResolvedMember $m): string => Address::of($m->record->path, $m->record->line, $m->record->column),
            $members,
        );
        $width = Address::width($addresses);

        $sections = [];

        foreach ($groups as $label => $group) {
            $sections[] = new Section((string) $label, array_map(
                fn (ResolvedMember $member): Row => $this->row($member, $width),
                $group,
            ));
        }

        return $sections;
    }

    private function row(ResolvedMember $member, int $width): Row
    {
        $record = $member->record;
        $signature = $record->signature.($record->isPromoted ? '  (promoted)' : '');

        return new Row(
            file: $record->path,
            line: $record->line,
            column: $record->column,
            fqn: $record->fqn,
            kind: $record->kind->value,
            text: rtrim(str_pad(Address::of($record->path, $record->line, $record->column), $width).'  '.$signature),
            extra: [
                'origin' => $member->origin->value,
                'origin_fqn' => $member->originFqn,
                'promoted' => $record->isPromoted,
            ],
            carriesAddress: true,
        );
    }

    /**
     * @param  list<UnreachableAncestor>  $unreachable
     * @return list<Row>
     */
    private function unreachable(string $path, array $unreachable): array
    {
        return array_map(static fn (UnreachableAncestor $ancestor): Row => new Row(
            file: $path,
            line: null,
            column: null,
            fqn: $ancestor->fqn,
            kind: $ancestor->relation,
            text: $ancestor->relation.' '.$ancestor->fqn,
            confidence: Confidence::Textual,
            note: $ancestor->reason,
            carriesAddress: true,
        ), $unreachable);
    }

    /**
     * The index-free path: parse the one file and list what it declares.
     *
     * @param  list<string>  $notes
     */
    private function declaredOnly(SymbolRef $ref, CommandContext $context, array $notes = [], ?string $matching = null, string $why = 'members not listed with --own — drop the flag to resolve them'): Result
    {
        $located = $context->locator->locateOwner($ref);
        $declaration = $located->declaration;
        $path = $located->analysis->file->displayPath;
        $total = count($declaration->members);
        $members = $matching === null
            ? $declaration->members
            : array_values(array_filter(
                $declaration->members,
                static fn (Declaration $member): bool => preg_match($matching, $member->name) === 1,
            ));

        $rows = array_map(
            static fn (Declaration $member): Row => new Row(
                file: $path,
                line: $member->startLine,
                column: $member->startColumn,
                fqn: $member->fqn,
                kind: $member->kind->value,
                text: $member->signature.($member->isPromoted ? '  (promoted)' : ''),
                extra: ['origin' => 'own'] + ($member->isPromoted ? ['promoted' => true] : []),
            ),
            $members,
        );

        [$kept, $elided] = $context->budget->apply($rows);

        return new Result(
            header: $matching === null
                ? sprintf('%s  %s  (%d own members)', $path, $declaration->fqn, $total)
                : sprintf('%s  %s  (%d of %d own members matching)', $path, $declaration->fqn, count($rows), $total),
            sections: [new Section('own', $kept)],
            unresolvedTitle: 'not listed',
            unresolved: $this->notListed($path, $declaration, $why),
            elided: $elided,
            notes: [...$notes, ...$context->notes()],
            found: $matching === null || $rows !== [],
        );
    }

    /**
     * @return list<Row>
     */
    private function notListed(string $path, Declaration $declaration, string $why): array
    {
        $rows = [];

        foreach ([['extends', $declaration->extends], ['implements', $declaration->implements], ['use', $declaration->traits]] as [$keyword, $names]) {
            foreach ($names as $name) {
                $rows[] = new Row(
                    file: $path,
                    line: $declaration->startLine,
                    column: $declaration->startColumn,
                    fqn: $name,
                    kind: $keyword,
                    text: $keyword.' '.$name,
                    confidence: Confidence::Textual,
                    note: $why,
                );
            }
        }

        return $rows;
    }
}

<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Console\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Vusys\Exocomp\Analysis\Declaration;
use Vusys\Exocomp\Console\CommandContext;
use Vusys\Exocomp\Console\ExocompCommand;
use Vusys\Exocomp\Output\Result;
use Vusys\Exocomp\Output\Row;
use Vusys\Exocomp\Output\Section;
use Vusys\Exocomp\Symbol\Confidence;
use Vusys\Exocomp\Symbol\SymbolRef;

/**
 * A class's declared API, in one call.
 *
 * This is the answer to candidate-name guessing: 661 of the measured searches OR'd four or more
 * plausible method names together and let grep say which existed, with a `-A 22` guess at the
 * body length on top. Listing the members answers that exactly, with nothing guessed.
 *
 * This build lists *declared* members only. Where a class inherits or uses traits, the
 * unresolved block says so and names what to run next — an honest gap the caller can close in
 * one more call, rather than a list that silently reads as the whole API.
 */
#[AsCommand(name: 'members', description: 'List the members a class declares, with the line each is on')]
final class MembersCommand extends ExocompCommand
{
    protected function configure(): void
    {
        parent::configure();

        $this
            ->addArgument('class', InputArgument::REQUIRED, 'Class, interface, trait or enum name')
            ->addOption('own', null, InputOption::VALUE_NONE, 'Suppress the trailing block naming unlisted inherited and trait members');
    }

    protected function answer(InputInterface $input, CommandContext $context): Result
    {
        $ref = SymbolRef::parse($this->stringArgument($input, 'class') ?? '');
        $located = $context->locator->locateOwner($ref);
        $declaration = $located->declaration;
        $path = $located->analysis->file->displayPath;

        $rows = array_map(
            static fn (Declaration $member): Row => self::row($path, $member),
            $declaration->members,
        );

        [$kept, $elided] = $context->budget->apply($rows);

        return new Result(
            header: sprintf('%s  %s  (%d own members)', $path, $declaration->fqn, count($rows)),
            sections: [new Section('own', $kept)],
            unresolvedTitle: 'not listed',
            unresolved: $this->boolOption($input, 'own') ? [] : $this->unlisted($path, $declaration),
            elided: $elided,
            notes: $context->locator->notes(),
        );
    }

    private static function row(string $path, Declaration $member): Row
    {
        $text = $member->signature;

        if ($member->isPromoted) {
            $text .= '  (promoted)';
        }

        return new Row(
            file: $path,
            line: $member->startLine,
            column: $member->startColumn,
            fqn: $member->fqn,
            kind: $member->kind->value,
            text: $text,
            extra: ['origin' => 'own'] + ($member->isPromoted ? ['promoted' => true] : []),
        );
    }

    /**
     * Everything whose members this build cannot reach, each with the command that would
     * reach it. The reason column is the point: it turns a limitation into a next step.
     *
     * @return list<Row>
     */
    private function unlisted(string $path, Declaration $declaration): array
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
                    note: 'members not listed here — try: exocomp members '.$name,
                );
            }
        }

        return $rows;
    }
}

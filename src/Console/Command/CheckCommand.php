<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Console\Command;

use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vusys\Exocomp\Console\CommandContext;
use Vusys\Exocomp\Console\IndexedCommand;
use Vusys\Exocomp\Output\Address;
use Vusys\Exocomp\Output\Counted;
use Vusys\Exocomp\Output\Result;
use Vusys\Exocomp\Output\Row;
use Vusys\Exocomp\Output\Section;
use Vusys\Exocomp\Query\QueryEngine;
use Vusys\Exocomp\Rules\Baseline;
use Vusys\Exocomp\Rules\Rule;
use Vusys\Exocomp\Rules\RuleSet;
use Vusys\Exocomp\Rules\Violation;
use Vusys\Exocomp\Source\SourceFile;

/**
 * The CI face of the tool: run a saved rule set, fail on anything new.
 *
 * This is what converts a one-off audit into a permanent guard. The sweeps that have found real
 * bug families — entity lookups without tenant scoping, deletes that orphan child rows, two
 * write paths with divergent invariants — are all one pattern plus a predicate, and every one of
 * them decays the moment nobody re-runs it. A baseline makes them adoptable on a codebase that
 * does not currently pass: the rule starts guarding immediately, and the known set can only
 * shrink.
 *
 * Exit codes are linter-shaped rather than search-shaped: 0 clean, 1 new violations, 2 error.
 */
#[AsCommand(name: 'check', description: 'Run a saved rule set and fail on violations that are not in the baseline')]
final class CheckCommand extends IndexedCommand
{
    private bool $hasNewViolations = false;

    protected function configure(): void
    {
        parent::configure();

        $this
            ->addArgument('rules', InputArgument::OPTIONAL, 'Rule file (defaults to exocomp-rules.json in the project root)')
            ->addOption('baseline', null, InputOption::VALUE_REQUIRED, 'Baseline file of already-known violations')
            ->addOption('update-baseline', null, InputOption::VALUE_NONE, 'Record every current violation as known, and exit 0')
            ->setHelp(<<<'HELP'
            A rule file is JSON:

              {
                "rules": [
                  {
                    "id": "unscoped-uuid-lookup",
                    "message": "Entity looked up by uuid without tenant scoping",
                    "pattern": "$R->findByUuid($U)",
                    "in": ["App\\Repositories\\**"]
                  }
                ]
              }

            Every `query` predicate is available as a key: in, not-in, annotated,
            has-attribute, arg-is-literal, arg-is-not-literal, param-count, visibility,
            returns, uses-trait, extends, implements, receiver-type, receiver-implements,
            receiver-extends.

            Exit codes: 0 no new violations, 1 new violations, 2 error, 3 no new violations but a
            rule could not decide some matches because the receiver's type is not known.
            HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $exit = parent::execute($input, $output);

        // `check` reports rather than searches, so a run that found violations must not be
        // reported as a run that found nothing.
        // No new violation, but a rule could not decide some matches for want of a type: the
        // pass is not a clean one, so it says so the way every other command does.
        return match (true) {
            $exit === self::EXIT_FOUND && $this->hasNewViolations => self::EXIT_NOT_FOUND,
            $exit === self::EXIT_FOUND && array_sum($this->undecided) > 0 => self::EXIT_INCOMPLETE,
            default => $exit,
        };
    }

    protected function answer(InputInterface $input, CommandContext $context): Result
    {
        $notes = [];
        $this->graph($input, $context, $notes);

        if (! $this->boolOption($input, 'no-sync')) {
            $context->index()->freshness($this->boolOption($input, 'vendor'))->full();
        }

        $rules = RuleSet::fromFile($this->rulePath($input, $context));
        $violations = $this->collectViolations($rules, $context);
        $baselinePath = $this->stringOption($input, 'baseline');

        if ($this->boolOption($input, 'update-baseline')) {
            // Next to the rules file, the way the rules file itself is found.
            $baselinePath ??= dirname($this->rulePath($input, $context)).'/exocomp-baseline.json';

            Baseline::write($baselinePath, $violations);

            return new Result(
                header: sprintf('%s recorded in %s', Counted::of(count($violations), 'violation'), $baselinePath),
                notes: $notes,
                found: true,
            );
        }

        $baseline = $baselinePath === null || ! is_file($baselinePath)
            ? Baseline::empty()
            : Baseline::fromFile($baselinePath);

        $partition = $baseline->partition($violations);
        $this->hasNewViolations = $partition['new'] !== [];

        $stale = $baseline->stale($violations);

        if ($stale > 0) {
            $notes[] = sprintf(
                '%d baselined violation%s no longer occur%s — re-run with --update-baseline to tighten the ratchet',
                $stale,
                $stale === 1 ? '' : 's',
                $stale === 1 ? 's' : '',
            );
        }

        $width = Address::width(array_map(
            static fn (Violation $violation): string => Address::of($violation->path, $violation->line, $violation->column),
            $partition['new'],
        ));

        [$kept, $elided] = $context->budget->apply($partition['new']);

        return new Result(
            header: sprintf(
                '%d violation%s (%d new, %d baselined)',
                count($violations),
                count($violations) === 1 ? '' : 's',
                count($partition['new']),
                count($partition['waived']),
            ),
            sections: [new Section('', array_map(
                static fn (Violation $violation): Row => new Row(
                    file: $violation->path,
                    line: $violation->line,
                    column: $violation->column,
                    fqn: $violation->context,
                    kind: $violation->ruleId,
                    text: rtrim(str_pad($violation->ruleId, 24).'  '.str_pad(Address::of($violation->path, $violation->line, $violation->column), $width).'  '.$violation->message),
                    extra: ['message' => $violation->message, 'code' => $violation->snippet],
                    carriesAddress: true,
                ),
                $kept,
            ))],
            elided: $elided,
            notes: [...$notes, ...$this->undecidedNotes(), ...$context->notes()],
            // A report: the exit is decided in execute(), from the violations.
            found: true,
        );
    }

    /**
     * Per rule, the matches a receiver predicate could not decide for want of a type: a rule
     * that passes because the code it targets is untyped has to say so.
     *
     * @var array<string, int>
     */
    private array $undecided = [];

    /**
     * @return list<string>
     */
    private function undecidedNotes(): array
    {
        $notes = [];

        foreach ($this->undecided as $rule => $count) {
            if ($count > 0) {
                $notes[] = sprintf('%s: %d match%s not decided, because the receiver\'s type is not known (exocomp query shows them)', $rule, $count, $count === 1 ? ' was' : 'es were');
            }
        }

        return $notes;
    }

    /**
     * @return list<Violation>
     */
    private function collectViolations(RuleSet $rules, CommandContext $context): array
    {
        $violations = [];

        foreach ($rules->rules as $rule) {
            $engine = new QueryEngine($rule->predicates, $context->index()->graph());
            $paths = $this->candidatePaths($context, $rule->pattern, $rule->predicates);

            foreach ($paths as $path) {
                $absolute = $context->project->path($path);

                if (! is_file($absolute)) {
                    continue;
                }

                [$matches, $undecided] = $engine->runDeciding($rule->pattern, SourceFile::fromPath($absolute, $path));

                foreach ($matches as $match) {
                    $violations[] = Violation::from($rule, $match);
                }

                $this->undecided[$rule->id] = ($this->undecided[$rule->id] ?? 0) + count($undecided);
            }
        }

        usort($violations, static fn (Violation $a, Violation $b): int => [$a->ruleId, $a->path, $a->line]
            <=> [$b->ruleId, $b->path, $b->line]);

        return $violations;
    }

    private function rulePath(InputInterface $input, CommandContext $context): string
    {
        $given = $this->stringArgument($input, 'rules');

        if ($given !== null) {
            return $given;
        }

        $default = $context->project->path('exocomp-rules.json');

        if (! is_file($default)) {
            throw new RuntimeException(sprintf(
                'no rule file given and none at %s — see: exocomp check --help',
                $context->project->displayPath($default),
            ));
        }

        return $default;
    }
}

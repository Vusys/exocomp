<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Console\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Vusys\Exocomp\Console\CommandContext;
use Vusys\Exocomp\Console\IndexedFrameworkCommand;
use Vusys\Exocomp\Framework\Laravel\EloquentModel;
use Vusys\Exocomp\Framework\Laravel\LaravelProfile;
use Vusys\Exocomp\Framework\Laravel\ModelFact;
use Vusys\Exocomp\Framework\Laravel\ModelReader;
use Vusys\Exocomp\Output\Address;
use Vusys\Exocomp\Output\Result;
use Vusys\Exocomp\Output\Row;
use Vusys\Exocomp\Output\Section;
use Vusys\Exocomp\Project\ClassSource;
use Vusys\Exocomp\Project\SymbolNotFound;

/**
 * Everything an Eloquent model is, in one call.
 *
 * The 139 measured searches for `$table`, `$fillable` and `$casts` were mostly a file-opening
 * exercise: the answer is routinely on a parent or flattened in from a trait, so finding
 * nothing in the model's own body is the normal first result rather than the answer. This
 * walks the chain once and says which class each fact came from.
 *
 * The relations are the part no language-only tool gives you. `phpactor` can say that
 * `lines()` exists and returns `HasMany`; it cannot say that the far end is `App\Models\OrderLine`,
 * which is the thing being asked. And where the far end genuinely is not knowable — a `morphTo`,
 * a class named by a computed string — that is reported as a finding rather than as an absence.
 */
#[AsCommand(name: 'model', description: 'Describe an Eloquent model: table, attributes, casts, relations, scopes and traits')]
final class ModelCommand extends IndexedFrameworkCommand
{
    /**
     * The order facts are presented in — most-asked first. `table` and the relations are what
     * the measured searches were after; the rest is what someone reads next.
     *
     * @var list<array{string, string}>
     */
    private const array GROUPS = [
        ['attribute', 'attributes'],
        ['relation', 'relations'],
        ['casts', 'casts'],
        ['fillable', 'fillable'],
        ['guarded', 'guarded'],
        ['hidden', 'hidden'],
        ['visible', 'visible'],
        ['appends', 'appends'],
        ['with', 'eager loaded'],
        ['touches', 'touches'],
        ['dates', 'dates'],
        ['scope', 'global scopes'],
        ['trait', 'traits'],
    ];

    protected function configure(): void
    {
        parent::configure();

        $this
            ->addArgument('model', InputArgument::REQUIRED, 'A model class, by short name (Order) or fully-qualified name')
            ->addOption('only', null, InputOption::VALUE_REQUIRED, 'Show one group only: '.implode(', ', array_column(self::GROUPS, 0)));
    }

    protected function answer(InputInterface $input, CommandContext $context): Result
    {
        $notes = [];
        $graph = $this->graph($input, $context, $notes);
        $profile = $this->laravel($context);

        $name = trim($this->stringArgument($input, 'model') ?? '', '\\');
        $record = $this->resolveClass($graph->byName($name), $name, $notes);

        $reader = new ModelReader($graph, new ClassSource($context->project, $graph));

        if (! $reader->isModel($record->fqn)) {
            throw new SymbolNotFound($record->fqn, sprintf(
                '%s is not an Eloquent model — nothing in its ancestry extends %s. Use "exocomp members %s" to read it as an ordinary class.',
                $record->fqn,
                LaravelProfile::MODEL,
                $record->fqn,
            ));
        }

        $model = $reader->read(
            $record->fqn,
            static fn (): array => $profile->schema($context->project)->tables(),
        );

        if (! $model instanceof EloquentModel) {
            throw new SymbolNotFound($record->fqn, sprintf('%s is indexed but its source could not be read', $record->fqn));
        }

        return $this->present($model, $input, $context, $notes);
    }

    /**
     * @param  list<string>  $notes
     */
    private function present(EloquentModel $model, InputInterface $input, CommandContext $context, array $notes): Result
    {
        $only = $this->stringOption($input, 'only');
        $sections = [];
        $shown = 0;

        foreach (self::GROUPS as [$group, $title]) {
            if ($only !== null && $only !== $group) {
                continue;
            }

            $facts = $model->group($group);

            if ($facts === []) {
                continue;
            }

            $width = Address::width(array_map(
                static fn (ModelFact $fact): string => Address::of($fact->path, $fact->line),
                $facts,
            ));

            $sections[] = new Section($title, array_map(
                fn (ModelFact $fact): Row => $this->row($fact, $width),
                $facts,
            ));

            $shown += count($facts);
        }

        if ($only !== null && $sections === []) {
            throw new SymbolNotFound($only, sprintf('%s has nothing in the "%s" group', $model->fqn, $only));
        }

        $declinedWidth = Address::width(array_map(
            static fn (ModelFact $fact): string => Address::of($fact->path, $fact->line),
            $model->declined,
        ));

        [$kept, $elided] = $context->budget->apply(array_map(
            fn (ModelFact $fact): Row => $this->row($fact, $declinedWidth, withNote: false),
            $model->declined,
        ));

        return new Result(
            header: sprintf('%s   %s', $model->fqn, Address::of($model->path, $model->line)),
            sections: $sections,
            unresolvedTitle: 'not read from the source',
            unresolved: $only === null ? $kept : [],
            elided: $only === null ? $elided : 0,
            notes: [...$notes, ...$model->notes, ...$context->notes()],
        );
    }

    /**
     * @param  bool  $withNote  false for the trailing block, which renders the reason in its own
     *                          column — spelling it out twice would double the widest line in
     *                          the answer
     */
    private function row(ModelFact $fact, int $width, bool $withNote = true): Row
    {
        $label = $fact->value === null ? $fact->name : sprintf('%-22s %s', $fact->name, $fact->value);
        $text = rtrim(str_pad(Address::of($fact->path, $fact->line), $width).'  '.$label.($withNote && $fact->note !== null ? '   '.$fact->note : ''));

        return new Row(
            file: $fact->path,
            line: $fact->line,
            column: null,
            fqn: $fact->declaredBy,
            kind: $fact->group,
            text: $text,
            confidence: $fact->confidence,
            note: $fact->note,
            extra: ['name' => $fact->name, 'value' => $fact->value],
            carriesAddress: true,
        );
    }
}

<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Console\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Vusys\Exocomp\Console\CommandContext;
use Vusys\Exocomp\Console\IndexedFrameworkCommand;
use Vusys\Exocomp\Framework\Laravel\ConfigEntry;
use Vusys\Exocomp\Framework\Laravel\ConfigReader;
use Vusys\Exocomp\Output\Address;
use Vusys\Exocomp\Output\Result;
use Vusys\Exocomp\Output\Row;
use Vusys\Exocomp\Output\Section;
use Vusys\Exocomp\Project\ClassSource;
use Vusys\Exocomp\Project\SymbolNotFound;

/**
 * Where a config key is defined and where it is read.
 *
 * 307 measured searches wanted one of those halves. Neither is a question grep answers: a
 * definition is a nested array key, so the dotted name the code uses appears nowhere in the
 * file that defines it, and a read is a string literal that grep finds in comments and
 * fixtures alongside the real call sites.
 *
 * Reads are found by parsing, so what comes back is a call rather than a string; a read whose
 * key is computed is reported as such rather than skipped, because "no reads" and "no reads I
 * could see" are different answers.
 */
#[AsCommand(name: 'config', description: 'Find where a config key is defined and where it is read')]
final class ConfigCommand extends IndexedFrameworkCommand
{
    protected function configure(): void
    {
        parent::configure();

        $this
            ->addArgument('key', InputArgument::REQUIRED, 'A dotted config key, e.g. services.stripe.key')
            ->addOption('defined', null, InputOption::VALUE_NONE, 'Only where it is defined; skips the corpus scan')
            ->addOption('read', null, InputOption::VALUE_NONE, 'Only where it is read');
    }

    protected function answer(InputInterface $input, CommandContext $context): Result
    {
        $notes = [];

        // Called for what it enforces rather than for what it returns: config keys are not
        // symbols, but --rev still has to be refused here and a cold index still has to say so.
        $this->graph($input, $context, $notes);
        $profile = $this->laravel($context);
        $key = trim($this->stringArgument($input, 'key') ?? '');

        // The read half is a corpus scan over the files the index knows about, so a file written
        // since the last full pass would simply not be looked at — and its call site would be
        // missing from an answer that gave no sign anything was skipped.
        if (! $this->boolOption($input, 'defined') && ! $this->boolOption($input, 'no-sync')) {
            $context->index()->freshness($this->boolOption($input, 'vendor'))->full();
        }

        $reader = new ConfigReader(constant: new ClassSource($context->project, $context->index()->graph(validate: false), $context->locator)->constantValue(...));
        $reader->readDirectory(
            $profile->configDirectory($context->project),
            static fn (string $absolute): string => $context->project->displayPath($absolute),
        );

        $wantsRead = ! $this->boolOption($input, 'defined');
        $wantsDefined = ! $this->boolOption($input, 'read');

        $definitions = $wantsDefined ? $reader->matching($key) : [];
        $usages = $wantsRead
            ? $reader->usages(
                $key,
                $context->index()->store->paths($context->project->root),
                static fn (string $path): string => $context->project->path($path),
            )
            : [];

        // Writes are their own answer: "who changes this at runtime?" is a different question.
        $writes = array_values(array_filter($usages, static fn (ConfigEntry $entry): bool => str_starts_with($entry->kind, 'write: ') && $entry->key !== '?'));
        $usages = array_values(array_filter($usages, static fn (ConfigEntry $entry): bool => ! str_starts_with($entry->kind, 'write: ') || $entry->key === '?'));
        $certain = array_values(array_filter($usages, static fn (ConfigEntry $entry): bool => $entry->key !== '?'));
        $computed = array_values(array_filter($usages, static fn (ConfigEntry $entry): bool => $entry->key === '?'));

        if ($definitions === [] && $certain === []) {
            throw new SymbolNotFound($key, sprintf(
                'no config file defines "%s"%s',
                $key,
                $wantsRead ? ' and nothing reads it by that name' : '',
            ));
        }

        $sections = [];

        if ($definitions !== []) {
            $sections[] = new Section('defined', $this->rows($definitions));
        }

        [$kept, $elided] = $context->budget->apply($this->rows($certain));

        if ($certain !== []) {
            $sections[] = new Section('read', $kept);
        }

        if ($writes !== []) {
            $sections[] = new Section('written', $this->rows($writes));
        }

        // The computed reads go in the trailing block rather than being dropped: a key with
        // twenty call sites and one `config($name)` somewhere is a key whose blast radius is
        // not twenty.
        return new Result(
            header: sprintf('%s — %d definition%s, %d read%s', $key, count($definitions), count($definitions) === 1 ? '' : 's', count($certain), count($certain) === 1 ? '' : 's'),
            sections: $sections,
            unresolvedTitle: count($computed) === 1 ? 'read whose key is not a literal' : 'reads whose key is not a literal',
            unresolved: $this->rows($computed, withNote: false),
            elided: $elided,
            notes: [...$notes, ...$reader->notes(), ...$context->notes()],
        );
    }

    /**
     * @param  list<ConfigEntry>  $entries
     * @param  bool  $withNote  false for the trailing block, which renders the reason in its own
     *                          column
     * @return list<Row>
     */
    private function rows(array $entries, bool $withNote = true): array
    {
        $width = Address::width(array_map(
            static fn (ConfigEntry $entry): string => Address::of($entry->path, $entry->line),
            $entries,
        ));

        return array_map(
            static fn (ConfigEntry $entry): Row => new Row(
                file: $entry->path,
                line: $entry->line,
                column: null,
                fqn: null,
                kind: $entry->kind,
                text: rtrim(sprintf(
                    '%s  %-46s %s%s',
                    str_pad(Address::of($entry->path, $entry->line), $width),
                    $entry->key,
                    $entry->value ?? (str_starts_with($entry->kind, 'write: ') ? substr($entry->kind, 7) : $entry->kind),
                    $withNote && $entry->note !== null ? '   '.$entry->note : '',
                )),
                confidence: $entry->confidence,
                note: $entry->note,
                extra: ['key' => $entry->key, 'value' => $entry->value],
                carriesAddress: true,
            ),
            $entries,
        );
    }
}

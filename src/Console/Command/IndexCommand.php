<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Console\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Vusys\Exocomp\Console\CommandContext;
use Vusys\Exocomp\Console\IndexedCommand;
use Vusys\Exocomp\Output\Result;
use Vusys\Exocomp\Output\Row;
use Vusys\Exocomp\Output\Section;

/**
 * Maintenance, and nothing that any other command depends on.
 *
 * `--warm` exists so the first graph query can be fast rather than so that it can work at all.
 * If this command is never run, everything else still answers — it just pays for the walk
 * itself the first time.
 */
#[AsCommand(name: 'index', description: 'Report on the symbol cache, or bring it up to date')]
final class IndexCommand extends IndexedCommand
{
    protected function configure(): void
    {
        parent::configure();

        $this
            ->addOption('warm', null, InputOption::VALUE_NONE, 'Bring the index up to date now rather than on the next query')
            ->addOption('no-vendor', null, InputOption::VALUE_NONE, 'Remove dependencies from the index; once indexed with --vendor they stay until this is run')
            ->addOption('status', null, InputOption::VALUE_NONE, 'Report what the index holds (the default)');
    }

    protected function answer(InputInterface $input, CommandContext $context): Result
    {
        $index = $context->index();
        $notes = [];

        $dropVendor = $this->boolOption($input, 'no-vendor');

        if ($this->boolOption($input, 'warm') || $dropVendor) {
            $report = $index->sync($this->boolOption($input, 'vendor'), $dropVendor);

            $notes[] = sprintf(
                '%d files seen, %d parsed, %d reused, %d removed, %.2fs',
                $report->seen,
                $report->parsed,
                $report->reused,
                $report->removed,
                $report->seconds,
            );
        }

        $statistics = $index->statistics();
        $rows = [
            $this->line('cache', $index->store->path),
            $this->line('project', $context->project->root),
            $this->line('roots', implode(', ', array_map($context->project->displayPath(...), $index->roots($this->boolOption($input, 'vendor'), $dropVendor)))),
            $this->line('files', (string) $statistics['files']),
            $this->line('symbols', (string) $statistics['symbols']),
            $this->line('inheritance edges', (string) $statistics['edges']),
            $this->line('extracted contents', (string) $statistics['blobs']),
            $this->line('files that will not parse', (string) $statistics['failures']),
            // The whole justification for hashing contents rather than paths: this is how much
            // of the current checkout another checkout had already paid for.
            $this->line('shared with other checkouts', sprintf('%d files', $statistics['shared'])),
        ];

        return new Result(
            header: 'exocomp index',
            sections: [new Section('', $rows)],
            notes: $notes,
            found: true,
        );
    }

    private function line(string $label, string $value): Row
    {
        return new Row(
            file: '',
            line: null,
            column: null,
            fqn: null,
            kind: 'index',
            text: str_pad($label, 28).$value,
            extra: [$label => $value],
        );
    }
}

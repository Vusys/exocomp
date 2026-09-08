<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Console\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Vusys\Exocomp\Console\CommandContext;
use Vusys\Exocomp\Console\IndexedFrameworkCommand;
use Vusys\Exocomp\Framework\Laravel\DispatchEdge;
use Vusys\Exocomp\Framework\Laravel\DispatchReader;
use Vusys\Exocomp\Output\Address;
use Vusys\Exocomp\Output\Result;
use Vusys\Exocomp\Output\Row;
use Vusys\Exocomp\Output\Section;
use Vusys\Exocomp\Project\ClassSource;
use Vusys\Exocomp\Project\SymbolNotFound;

/**
 * Where a job or event is sent, and what receives it.
 *
 * This is the edge that leaves a framework call graph disconnected at its most interesting
 * joint. `dispatch(new ProcessOrder)` continues execution in another class, in another process,
 * possibly minutes later, and nothing at the call site names the code that runs — which is why
 * `calls-from` reports it as unresolved, correctly, and why the 65 measured searches for jobs,
 * events and listeners existed at all.
 *
 * The receivers are not equally certain and are not presented as though they were. A job
 * handling itself is a fact about the class. A listener in a provider's `$listen` array is a
 * declaration. A listener found by the type of its `handle()` parameter is the framework's
 * discovery convention — reliably true in practice, and inferred here, because whether
 * discovery is switched on is not something the source says.
 */
#[AsCommand(name: 'dispatches', description: 'Find where a job or event is dispatched, and what handles it')]
final class DispatchesCommand extends IndexedFrameworkCommand
{
    protected function configure(): void
    {
        parent::configure();

        $this->addArgument('class', InputArgument::REQUIRED, 'A job or event class, by short name or fully-qualified name');
    }

    protected function answer(InputInterface $input, CommandContext $context): Result
    {
        $notes = [];
        $graph = $this->graph($input, $context, $notes);
        $this->laravel($context);

        $name = trim($this->stringArgument($input, 'class') ?? '', '\\');
        $record = $this->resolveClass($graph->byName($name), $name, $notes);

        if (! $this->boolOption($input, 'no-sync')) {
            $context->index()->freshness($this->boolOption($input, 'vendor'))->full();
        }

        // The index's name table is exactly the right narrowing here: a file that dispatches
        // this class mentions it, and one that does not cannot. Nothing is excluded that could
        // have matched, and the corpus is not parsed.
        $paths = $context->index()->store->pathsMentioning($context->project->root, [$this->shortName($record->fqn)]);

        $reader = new DispatchReader(new ClassSource($context->project, $graph, $context->locator));
        $reader->read($record->fqn, $paths, static fn (string $path): string => $context->project->path($path));

        $sites = $reader->sites();
        $receivers = $reader->receivers();

        if ($sites === [] && $receivers === []) {
            throw new SymbolNotFound($record->fqn, sprintf(
                '%s is never dispatched and nothing handles it, across the %d file%s that mention it',
                $record->fqn,
                $reader->filesScanned(),
                $reader->filesScanned() === 1 ? '' : 's',
            ));
        }

        $sections = [];

        if ($sites !== []) {
            [$kept, $elided] = $context->budget->apply($this->rows($sites));
            $sections[] = new Section('dispatched', $kept);
        } else {
            $elided = 0;
        }

        if ($receivers !== []) {
            $sections[] = new Section('handled by', $this->rows($receivers));
        }

        return new Result(
            header: sprintf(
                '%s — dispatched in %d place%s, handled in %d',
                $record->fqn,
                count($sites),
                count($sites) === 1 ? '' : 's',
                count($receivers),
            ),
            sections: $sections,
            elided: $elided,
            notes: [
                ...$notes,
                ...($receivers === [] ? ['nothing was found that receives this; if it is handled by a listener registered at runtime, that is not visible from the source'] : []),
                ...$context->notes(),
            ],
        );
    }

    /**
     * @param  list<DispatchEdge>  $edges
     * @return list<Row>
     */
    private function rows(array $edges): array
    {
        $width = Address::width(array_map(
            static fn (DispatchEdge $edge): string => Address::of($edge->path, $edge->line),
            $edges,
        ));

        return array_map(
            static fn (DispatchEdge $edge): Row => new Row(
                file: $edge->path,
                line: $edge->line,
                column: null,
                fqn: $edge->counterpart,
                kind: $edge->kind,
                text: rtrim(sprintf(
                    '%s  %-48s%s',
                    str_pad(Address::of($edge->path, $edge->line), $width),
                    $edge->counterpart ?? '?',
                    $edge->note === null ? '' : '   '.$edge->note,
                )),
                confidence: $edge->confidence,
                note: $edge->note,
                extra: ['subject' => $edge->subject, 'counterpart' => $edge->counterpart],
                carriesAddress: true,
            ),
            $edges,
        );
    }

    private function shortName(string $fqn): string
    {
        $position = strrpos($fqn, '\\');

        return $position === false ? $fqn : substr($fqn, $position + 1);
    }
}

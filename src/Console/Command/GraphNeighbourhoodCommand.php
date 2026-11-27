<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Console\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Vusys\Exocomp\Console\CommandContext;
use Vusys\Exocomp\Console\GraphCommand;
use Vusys\Exocomp\Graph\Edge;
use Vusys\Exocomp\Graph\Stop;
use Vusys\Exocomp\Graph\Walk;
use Vusys\Exocomp\Output\Address;
use Vusys\Exocomp\Output\Counted;
use Vusys\Exocomp\Output\Result;
use Vusys\Exocomp\Output\Row;
use Vusys\Exocomp\Output\Section;
use Vusys\Exocomp\Project\SymbolNotFound;
use Vusys\Exocomp\Symbol\Confidence;

/**
 * Everything within N steps, and every place the walk had to stop.
 *
 * Outgoing only, on purpose. The inverse question already has a better answer in `callers-of`,
 * which finds callers by searching the corpus for references rather than by expanding every
 * declaration in it — running that per node for a multi-level incoming walk would cost a corpus
 * sweep per step and return the same thing more slowly.
 *
 * The trailing block is the point as much as the neighbourhood is. A bounded outward walk that
 * lists forty reachable methods and says nothing about the eleven calls it could not follow
 * reads as a complete picture of a subsystem, and is not one.
 */
#[AsCommand(name: 'graph', description: 'The bounded outward neighbourhood of a declaration, with the edges that could not be followed')]
final class GraphNeighbourhoodCommand extends GraphCommand
{
    /** How much of the disconnected frontier to show before summarising the rest. */
    private const int MAX_STOPS = 25;

    protected function configure(): void
    {
        parent::configure();

        $this->addArgument('symbol', InputArgument::REQUIRED, 'A method or function address, e.g. "App\Http\Controllers\OrderController::store"');
    }

    protected function answer(InputInterface $input, CommandContext $context): Result
    {
        $notes = [];
        $graph = $this->graph($input, $context, $notes);
        $walk = $this->walk($input, $context, $graph, $notes);
        $symbol = $this->stringArgument($input, 'symbol') ?? '';
        $depth = max(1, $this->intOption($input, 'depth', 6));

        $found = $walk->neighbourhood($symbol, $depth);
        $this->refuseUnreadableStart($walk, $symbol);

        $edges = array_map(static fn (array $step): Edge => $step[1], $found);
        $width = $this->stepWidth($edges);
        $sections = [];

        for ($step = 1; $step <= $depth; $step++) {
            $atStep = array_values(array_filter($found, static fn (array $one): bool => $one[0] === $step));

            if ($atStep === []) {
                continue;
            }

            $sections[] = new Section(
                sprintf('%s out', Counted::of($step, 'step')),
                array_map(fn (array $one): Row => $this->step($one[1], $width), $atStep),
            );
        }

        [$shown, $hidden] = $this->frontier($walk->stops());

        return new Result(
            header: sprintf(
                '%d declaration%s within %d step%s of %s',
                count($found),
                count($found) === 1 ? '' : 's',
                $depth,
                $depth === 1 ? '' : 's',
                $symbol,
            ),
            sections: $sections,
            unresolvedTitle: 'not followed',
            unresolved: $shown,
            notes: [...$notes, ...$hidden, ...$this->walkNotes($walk), ...$context->notes()],
        );
    }

    /**
     * A start whose declaration could not be read is a "not found", not an empty neighbourhood.
     */
    private function refuseUnreadableStart(Walk $walk, string $symbol): void
    {
        foreach ($walk->stops() as $stop) {
            if ($stop->kind === Stop::UNREADABLE && $stop->from === $symbol) {
                throw new SymbolNotFound($symbol, sprintf('no declaration for "%s" could be read in the source roots or through the autoload map', $symbol));
            }
        }
    }

    /**
     * @param  list<Stop>  $stops
     * @return array{list<Row>, list<string>}
     */
    private function frontier(array $stops): array
    {
        $shown = array_slice($stops, 0, self::MAX_STOPS);
        $width = Address::width(array_map(
            static fn (Stop $stop): string => Address::of($stop->path, $stop->line, $stop->column),
            $shown,
        ));

        $rows = array_map(
            static fn (Stop $stop): Row => new Row(
                file: $stop->path,
                line: $stop->line === 0 ? null : $stop->line,
                column: $stop->column === 0 ? null : $stop->column,
                fqn: $stop->from,
                kind: $stop->kind,
                text: rtrim(str_pad(Address::of($stop->path, $stop->line, $stop->column), $width).'  '.$stop->detail),
                confidence: Confidence::Textual,
                note: $stop->reason,
                extra: ['from' => $stop->from, 'reason' => $stop->reason],
                carriesAddress: $stop->path !== '',
            ),
            $shown,
        );

        $notes = [];
        $remaining = count($stops) - count($shown);

        if ($remaining > 0) {
            $notes[] = $remaining === 1
                ? 'one further edge could not be followed and is not listed'
                : sprintf('%d further edges could not be followed and are not listed', $remaining);
        }

        return [$rows, $notes];
    }
}

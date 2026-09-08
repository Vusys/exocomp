<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Console;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Vusys\Exocomp\Framework\Detection;
use Vusys\Exocomp\Framework\Laravel\ContainerReader;
use Vusys\Exocomp\Framework\Laravel\LaravelProfile;
use Vusys\Exocomp\Graph\CodeEdges;
use Vusys\Exocomp\Graph\Edge;
use Vusys\Exocomp\Graph\LaravelEdges;
use Vusys\Exocomp\Graph\Walk;
use Vusys\Exocomp\Index\SymbolGraph;
use Vusys\Exocomp\Output\Address;
use Vusys\Exocomp\Output\Row;
use Vusys\Exocomp\Project\ClassSource;

/**
 * The shared setup for the two commands that walk the graph.
 *
 * The framework layer is attached when there is one and left off when there is not, rather
 * than being a precondition: the call graph of a plain PHP library is a perfectly good thing
 * to ask about, and refusing to answer because no `artisan` was found would be refusing over
 * something the question did not depend on. What a caller must never get is a *silently*
 * frameworkless walk of a framework application, so the absence is reported as a note.
 */
abstract class GraphCommand extends IndexedFrameworkCommand
{
    protected function configure(): void
    {
        parent::configure();

        $this
            ->addOption('depth', null, InputOption::VALUE_REQUIRED, 'How many steps to walk before stopping', '6')
            ->addOption('max-nodes', null, InputOption::VALUE_REQUIRED, 'How many declarations to expand before stopping', '1500')
            ->addOption('through-vendor', null, InputOption::VALUE_NONE, 'Walk on through dependencies instead of stopping at them');
    }

    /**
     * @param  list<string>  $notes
     */
    protected function walk(InputInterface $input, CommandContext $context, SymbolGraph $graph, array &$notes): Walk
    {
        $source = new ClassSource($context->project, $graph, $context->locator);
        $detection = new LaravelProfile()->detect($context->project);
        $framework = null;

        if ($detection instanceof Detection) {
            $framework = new LaravelEdges(
                $context->project,
                $graph,
                $source,
                $context->index()->store,
                fn (): ContainerReader => $this->container($input, $context, $graph),
            );
        } else {
            $notes[] = 'no framework was detected, so the walk has only the edges the language itself provides — a dispatch, a container binding or a facade ends it';
        }

        return new Walk(
            code: new CodeEdges($context->project, $graph, $source, $context->locator),
            framework: $framework,
            maxDepth: max(1, $this->intOption($input, 'depth', 6)),
            maxNodes: max(1, $this->intOption($input, 'max-nodes', 1500)),
            throughDependencies: $this->boolOption($input, 'through-vendor'),
        );
    }

    /**
     * One step, rendered: where the step was written, then where it goes.
     */
    protected function step(Edge $edge, int $width): Row
    {
        $address = Address::of($edge->path, $edge->line, $edge->column);

        return new Row(
            file: $edge->path,
            line: $edge->line,
            column: $edge->column,
            fqn: $edge->to,
            kind: $edge->kind,
            text: rtrim(str_pad($address, $width).'  '.$edge->kind.'  →  '.$edge->to),
            confidence: $edge->confidence,
            note: $edge->reason,
            extra: ['from' => $edge->from, 'reason' => $edge->reason],
            carriesAddress: true,
        );
    }

    /**
     * @param  list<Edge>  $edges
     */
    protected function stepWidth(array $edges): int
    {
        return Address::width(array_map(
            static fn (Edge $edge): string => Address::of($edge->path, $edge->line, $edge->column),
            $edges,
        ));
    }
}

<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Console\Command;

use PhpParser\Node\Stmt\ClassMethod;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Vusys\Exocomp\Console\CommandContext;
use Vusys\Exocomp\Console\GraphCommand;
use Vusys\Exocomp\Framework\Laravel\MiddlewareReader;
use Vusys\Exocomp\Framework\Laravel\RouteDefinition;
use Vusys\Exocomp\Framework\Laravel\RouteNamespace;
use Vusys\Exocomp\Framework\Laravel\RouteReader;
use Vusys\Exocomp\Framework\Laravel\RouteTable;
use Vusys\Exocomp\Graph\Edge;
use Vusys\Exocomp\Graph\Reachability;
use Vusys\Exocomp\Graph\Start;
use Vusys\Exocomp\Graph\Stop;
use Vusys\Exocomp\Graph\Target;
use Vusys\Exocomp\Graph\Walk;
use Vusys\Exocomp\Index\SymbolGraph;
use Vusys\Exocomp\Index\SymbolRecord;
use Vusys\Exocomp\Output\Address;
use Vusys\Exocomp\Output\Result;
use Vusys\Exocomp\Output\Row;
use Vusys\Exocomp\Output\Section;
use Vusys\Exocomp\Project\ClassSource;
use Vusys\Exocomp\Project\LoadedClass;
use Vusys\Exocomp\Project\SymbolNotFound;
use Vusys\Exocomp\Symbol\Confidence;
use Vusys\Exocomp\Symbol\SymbolRef;

/**
 * Is there a path from here to there.
 *
 * This is the query the whole read engine was built to make possible. A tenant-scoping audit —
 * does any endpoint reach this write without going through the scope — is that question asked
 * once per endpoint, and doing it by hand is a day of opening files. Asked here it is one call,
 * and `route:` as a starting point makes the sweep itself one call rather than one per route.
 *
 * The negative answer is the one that needs care. "No path" is a claim about a whole graph, and
 * a graph of a dynamic language is never complete — so a bare "no" would be precisely the
 * confidently-wrong answer this tool exists not to give. Every place the walk could not follow
 * is reported with the verdict, along with whether a depth or node budget cut the search short,
 * so a reader can see how much the "no" is actually worth.
 */
#[AsCommand(name: 'reaches', description: 'Whether a call path exists from one declaration (or route) to another')]
final class ReachesCommand extends GraphCommand
{
    /**
     * How many starting points a sweep will walk from before it stops and says so.
     *
     * A route table can be a thousand entries and each start is its own search. The cap is
     * generous enough for a real audit and disclosed when it bites, which is the difference
     * between a bounded answer and a misleading one.
     */
    private const int MAX_STARTS = 100;

    /** How much of the frontier a negative answer shows before summarising the rest. */
    private const int MAX_STOPS = 25;

    protected function configure(): void
    {
        parent::configure();

        $this
            ->addArgument('from', InputArgument::REQUIRED, 'Where to start: a method address, or "route:<pattern>" for every matching endpoint')
            ->addArgument('to', InputArgument::REQUIRED, 'What counts as arriving: "App\Models\Order::save", "App\Models\Order" for any member, or "::save" for any class\'s')
            ->addOption('routes', null, InputOption::VALUE_REQUIRED, 'For a route: start, a file holding `php artisan route:list --json`; by default bootstrap/cache/routes-v7.php, then exocomp-routes.json, is read')
            ->addOption('max-routes', null, InputOption::VALUE_REQUIRED, 'How many matching routes a route: sweep walks; 0 walks them all', (string) self::MAX_STARTS);
    }

    protected function answer(InputInterface $input, CommandContext $context): Result
    {
        $notes = [];
        $graph = $this->graph($input, $context, $notes);
        $walk = $this->walk($input, $context, $graph, $notes);
        $target = Target::parse($this->stringArgument($input, 'to') ?? '');
        $from = $this->stringArgument($input, 'from') ?? '';

        // An endpoint that does not exist is an error, never "no path": the walk would have
        // nothing to start from, or nothing that could ever be arrived at.
        if (! str_starts_with($from, 'route:')) {
            $from = $this->knownStart(SymbolRef::parse($from), $graph, $context);
        }

        $owner = $target->literalOwner();

        if ($owner !== null) {
            $known = $this->knownAddress(SymbolRef::parse($owner), $graph, $context)->owner;
            $target = strcasecmp($known, $owner) === 0 ? $target : $target->withOwner($known);
        }

        $matched = 0;
        $starts = $this->starts($from, $context, $notes, $this->intOption($input, 'max-routes', self::MAX_STARTS), $matched, $this->stringOption($input, 'routes'));

        return count($starts) === 1 && $matched <= 1
            ? $this->one($walk, $starts[0], $target, $context, $notes)
            : $this->sweep($walk, $starts, $target, $context, $notes, $matched);
    }

    /**
     * A start address that names a declaration: its class placed (and corrected, as `show`
     * does), and its member declared somewhere the index can see, unless the class's ancestry
     * leaves the index, where an absence proves nothing.
     */
    private function knownStart(SymbolRef $ref, SymbolGraph $graph, CommandContext $context): string
    {
        $ref = $this->knownAddress($ref, $graph, $context);

        if (! $ref->hasMember()) {
            return (string) $ref;
        }

        $members = $graph->members($ref->owner);

        foreach ($members['members'] as $member) {
            if (strcasecmp($member->record->name, (string) $ref->member) === 0) {
                return (string) $ref;
            }
        }

        if ($members['unreachable'] === [] && $graph->classLike($ref->owner) instanceof SymbolRecord) {
            throw new SymbolNotFound((string) $ref, sprintf('%s declares no member "%s"', $ref->owner, (string) $ref->member));
        }

        return (string) $ref;
    }

    /**
     * @param  list<string>  $notes
     */
    private function one(Walk $walk, Start $start, Target $target, CommandContext $context, array $notes): Result
    {
        $reach = $walk->reach($start->node, $target, $start->before);
        $width = $this->stepWidth($reach->path);

        if ($reach->found) {
            return new Result(
                header: $this->arrival($start, $target, $reach),
                sections: [new Section('', array_map(fn (Edge $edge): Row => $this->step($edge, $width), $reach->path))],
                notes: [...$notes, ...$this->coverage($reach), ...$this->walkNotes($walk), ...$context->notes()],
            );
        }

        [$shown, $hidden] = $this->frontier($reach);

        return new Result(
            header: $this->absence($start, $target, $reach),
            unresolvedTitle: 'not followed',
            unresolved: $shown,
            notes: [...$notes, ...$hidden, ...$this->coverage($reach), ...$this->walkNotes($walk), ...$context->notes()],
            found: false,
            complete: $reach->stops === [] && ! $reach->exhausted,
        );
    }

    /**
     * @param  list<Start>  $starts
     * @param  list<string>  $notes
     */
    private function sweep(Walk $walk, array $starts, Target $target, CommandContext $context, array $notes, int $matched = 0): Result
    {
        $matched = max($matched, count($starts));
        $rows = [];
        $reached = 0;
        $addressWidth = Address::width(array_map(
            $this->startAddress(...),
            $starts,
        ));
        $labelWidth = array_reduce($starts, static fn (int $widest, Start $start): int => max($widest, strlen($start->label)), 0);

        $complete = true;

        foreach ($starts as $start) {
            $reach = $walk->reach($start->node, $target, $start->before);
            $reached += $reach->found ? 1 : 0;
            $complete = $complete && ($reach->found || ($reach->stops === [] && ! $reach->exhausted));
            $rows[] = $this->verdict($start, $reach, $addressWidth, $labelWidth);
        }

        // The coverage is in the headline: a capped sweep that said "2 of 100" read as the answer
        // for the whole app, with the rest mentioned only in a note.
        $skipped = $matched - count($starts);

        return new Result(
            header: sprintf(
                '%d of %d starting point%s reach %s%s',
                $reached,
                count($starts),
                count($starts) === 1 ? '' : 's',
                (string) $target,
                $skipped > 0 ? sprintf('; %d more matched and were not walked (--max-routes %d; 0 walks them all)', $skipped, count($starts)) : '',
            ),
            sections: [new Section('', $rows)],
            notes: [
                ...$notes,
                'a sweep reports the verdict per starting point; ask about one of them directly to see its path and what the walk could not follow',
                ...$this->walkNotes($walk),
                ...$context->notes(),
            ],
            found: $reached > 0,
            complete: $complete && $matched <= count($starts),
        );
    }

    private function verdict(Start $start, Reachability $reach, int $addressWidth, int $labelWidth): Row
    {
        $summary = match (true) {
            $reach->found && $reach->path === [] => 'is the destination',
            $reach->found => sprintf('reached in %d step%s', count($reach->path), count($reach->path) === 1 ? '' : 's'),
            $reach->exhausted => 'not reached within the budget',
            default => 'not reached',
        };

        return new Row(
            file: $start->path,
            line: $start->line === 0 ? null : $start->line,
            column: $start->line === 0 ? null : 1,
            fqn: $start->node,
            kind: $reach->found ? 'reached' : 'not reached',
            text: rtrim(str_pad($this->startAddress($start), $addressWidth).'  '.str_pad($start->label, $labelWidth).'  '.$summary),
            confidence: $reach->found ? $reach->confidence() : Confidence::Textual,
            extra: ['steps' => count($reach->path), 'expanded' => $reach->expanded],
            carriesAddress: $start->path !== '',
        );
    }

    private function arrival(Start $start, Target $target, Reachability $reach): string
    {
        // Asking whether something reaches itself is a legitimate question with a degenerate
        // answer, and reporting it as "reaches it in 0 steps, weakest step resolved" invents a
        // step and a confidence for a walk that never happened.
        if ($reach->path === []) {
            return sprintf('%s is itself %s; no walk was needed', $start->label, (string) $target);
        }

        return sprintf(
            '%s%s reaches %s in %d step%s; the weakest step is %s',
            $start->label,
            $this->entry($start),
            (string) $target,
            count($reach->path),
            count($reach->path) === 1 ? '' : 's',
            $reach->confidence()->value,
        );
    }

    /**
     * The declaration a route actually starts at, named once in the header.
     *
     * A path from an endpoint begins somewhere inside a controller, and the rows show the file
     * but never the address the walk was given. Saying it here is the difference between an
     * answer a reader can re-ask about and one they have to reconstruct.
     */
    private function entry(Start $start): string
    {
        return $start->label === $start->node ? '' : sprintf(' (%s)', $start->node);
    }

    private function absence(Start $start, Target $target, Reachability $reach): string
    {
        return sprintf(
            'no path from %s%s to %s%s; %s walked',
            $start->label,
            $this->entry($start),
            (string) $target,
            $reach->exhausted ? ', within the depth and node budget' : '',
            $reach->expanded === 1 ? '1 declaration was' : sprintf('%d declarations were', $reach->expanded),
        );
    }

    /**
     * What the walk could not follow — the part that makes a "no" checkable.
     *
     * @return array{list<Row>, list<string>}
     */
    private function frontier(Reachability $reach): array
    {
        $stops = $reach->stops;
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
                ? 'one further place the walk could not follow is not listed'
                : sprintf('%d further places the walk could not follow are not listed', $remaining);
        }

        return [$rows, $notes];
    }

    /**
     * @return list<string>
     */
    private function coverage(Reachability $reach): array
    {
        $notes = [];
        $dependencies = count($reach->stopsOfKind(Stop::DEPENDENCY));

        if ($dependencies > 0 && ! $reach->found) {
            $notes[] = $dependencies === 1
                ? 'one edge led into a dependency and was not walked past; --through-vendor follows it'
                : sprintf('%d edges led into a dependency and were not walked past; --through-vendor follows them', $dependencies);
        }

        if ($reach->exhausted && ! $reach->found) {
            $notes[] = 'the search stopped at its budget rather than at the end of the graph — raise --depth or --max-nodes before reading this as an absence';
        }

        // The strongest thing a negative answer can say, and worth saying explicitly: nothing
        // was skipped. It is still a statement about the edges this tool models — a call
        // through `__call`, a string callable or a route macro is not one of them — but within
        // those, the search finished rather than gave up.
        if (! $reach->found && ! $reach->exhausted && $reach->stops === []) {
            $notes[] = 'the walk ran out of graph rather than out of budget, and every edge it met was followed — so within the edges this tool models, there is no path';
        }

        return $notes;
    }

    /**
     * A step into each middleware's `handle()`, which runs before the controller.
     *
     * @param  list<array{string, Confidence, string}>  $runs
     * @return list<Edge>
     */
    private function middlewareEdges(string $node, RouteDefinition $route, array $runs, ClassSource $classes, string $source): array
    {
        $edges = [];

        foreach ($runs as [$class, $confidence, $how]) {
            $loaded = $classes->load($class);

            if (! $loaded instanceof LoadedClass || ! $loaded->node->getMethod('handle') instanceof ClassMethod) {
                continue;
            }

            $edges[] = new Edge(
                from: $node,
                to: $loaded->fqn.'::handle',
                kind: 'middleware',
                path: $route->path,
                line: $route->line,
                column: 1,
                confidence: $confidence,
                reason: sprintf('runs before the controller: %s, read from %s', $how, $source),
            );
        }

        return $edges;
    }

    /**
     * A route the route files do not place has only the table it came from: no line, no column.
     */
    private function startAddress(Start $start): string
    {
        return $start->line === 0 ? Address::of($start->path) : Address::of($start->path, $start->line, 1);
    }

    /**
     * @param  list<string>  $notes
     * @return list<Start>
     */
    private function starts(string $from, CommandContext $context, array &$notes, int $limit = self::MAX_STARTS, int &$matched = 0, ?string $routes = null): array
    {
        if (! str_starts_with($from, 'route:')) {
            return [new Start($from, $from, '', 0)];
        }

        $pattern = substr($from, strlen('route:'));
        $profile = $this->laravel($context);
        $graph = $context->index()->graph(validate: false);
        $reader = new RouteReader(new RouteNamespace($graph, new ClassSource($context->project, $graph, $context->locator))->root());
        $reader->readDirectory(
            $profile->routesDirectory($context->project),
            static fn (string $absolute): string => $context->project->displayPath($absolute),
        );

        // The table Laravel builds, not the route files: they only place each route.
        $reader = RouteTable::forProject($context->project, $routes, $reader->routes(), $profile->routesDirectory($context->project));

        // Which table, and whether it is older than the route files, matter as much to a walk.
        $notes = [...$notes, ...$reader->notes()];

        $starts = [];
        $closures = 0;
        $middleware = MiddlewareReader::forProject($context->project);
        $classes = new ClassSource($context->project, $graph, $context->locator);
        $undeclared = [];

        foreach ($reader->routes() as $route) {
            if (! $this->routeMatches($route, $pattern)) {
                continue;
            }

            if ($route->controller === null || $route->action === null) {
                $closures++;

                continue;
            }

            $node = $route->controller.'::'.$route->action;
            [$runs, $unresolved] = $middleware->forRoute($route->middleware, $route->path);
            $undeclared = [...$undeclared, ...$unresolved];

            $starts[] = new Start(
                node: $node,
                label: $route->method.' '.$route->uri,
                path: $route->path,
                line: $route->line,
                before: $this->middlewareEdges($node, $route, $runs, $classes, $middleware->source()),
            );
        }

        if ($undeclared !== []) {
            $names = array_values(array_unique($undeclared));
            $notes[] = sprintf('middleware the application does not declare (%s) was not followed; the framework\'s own aliases and groups are not read', implode(', ', $names));
        }

        if ($starts === []) {
            throw new SymbolNotFound($from, sprintf(
                '%d route%s were read and none matches "%s"',
                count($reader->routes()),
                count($reader->routes()) === 1 ? '' : 's',
                $pattern,
            ));
        }

        if ($closures > 0) {
            $notes[] = sprintf('%d matching route%s answered by a closure rather than a controller, so there is no declaration to start from', $closures, $closures === 1 ? ' is' : 's are');
        }

        $matched = count($starts);

        if ($limit > 0 && count($starts) > $limit) {
            $starts = array_slice($starts, 0, $limit);
        }

        return $starts;
    }

    private function routeMatches(RouteDefinition $route, string $pattern): bool
    {
        if ($pattern === '' || $pattern === '*') {
            return true;
        }

        // `POST /both` matches a route answering several methods, `GET|POST /both`, as it does one.
        if (preg_match('/^([A-Za-z]+(?:\|[A-Za-z]+)*)\s+(\S.*)$/', $pattern, $parts) === 1
            && array_intersect(explode('|', strtoupper($parts[1])), explode('|', $route->method)) !== []
            && (Target::glob($parts[2], $route->uri) || $parts[2] === $route->uri)) {
            return true;
        }

        return array_any([$route->method.' '.$route->uri, $route->uri, (string) $route->name, $route->target()], fn (string $candidate): bool => Target::glob($pattern, $candidate) || str_contains($candidate, $pattern));
    }
}

<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Graph;

use Vusys\Exocomp\Inference\OutgoingCall;
use Vusys\Exocomp\Symbol\Identifier;

/**
 * Breadth-first, bounded, and honest about its bounds.
 *
 * Breadth-first because the shortest path is the one a reader can check: five steps that each
 * make obvious sense beat a fourteen-step wander that happens to arrive. Bounded because the
 * graph of a real application, once framework edges are attached, is large enough that an
 * unbounded walk is a corpus sweep with extra steps. And every bound is reported, because a
 * search that stopped early and a search that finished are the same output shape and must not
 * be the same claim.
 *
 * Dependencies are a wall by default. Expanding into the framework is unbounded in practice —
 * a single collection method reaches most of the library — and a reader chasing a path through
 * their own application does not want three hundred nodes of it. The edge *into* a dependency
 * is still recorded and still checked against the destination, so a walk that ends at a
 * framework method finds it; only walking onwards from there is opt-in.
 */
final class Walk
{
    /** @var list<Stop> */
    private array $stops = [];

    /** @var array<string, true> */
    private array $stopKeys = [];

    /** @var array<string, true> */
    private array $rerouted = [];

    public function __construct(
        private readonly CodeEdges $code,
        private readonly ?LaravelEdges $framework = null,
        private readonly int $maxDepth = 6,
        private readonly int $maxNodes = 1500,
        private readonly bool $throughDependencies = false,
    ) {}

    /**
     * Is there a path from here to there, and what is it.
     */
    public function reach(string $start, Target $target): Reachability
    {
        $this->stops = [];
        $this->stopKeys = [];

        if ($target->matches($start)) {
            return new Reachability($start, true, [], 0, false, []);
        }

        /** @var array<string, Edge> $arrivedBy */
        $arrivedBy = [];
        $seen = [Identifier::fold($start) => true];
        $queue = [[$start, 0]];
        $expanded = 0;
        $exhausted = false;

        while ($queue !== []) {
            [$node, $depth] = array_shift($queue);

            if ($depth >= $this->maxDepth) {
                $exhausted = true;

                continue;
            }

            if ($expanded >= $this->maxNodes) {
                $exhausted = true;

                break;
            }

            $expanded++;

            foreach ($this->edgesFrom($node) as $edge) {
                $key = Identifier::fold($edge->to);

                if ($target->matches($edge->to)) {
                    return new Reachability(
                        start: $start,
                        found: true,
                        path: [...$this->trace($arrivedBy, $edge->from), $edge],
                        expanded: $expanded,
                        exhausted: false,
                        stops: $this->stops,
                    );
                }

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $arrivedBy[$key] = $edge;
                $queue[] = [$edge->to, $depth + 1];
            }
        }

        return new Reachability($start, false, [], $expanded, $exhausted, $this->stops);
    }

    /**
     * Everything within N steps, with the edge that reached each one.
     *
     * @return list<array{int, Edge}>
     */
    public function neighbourhood(string $start, int $depth): array
    {
        $this->stops = [];
        $this->stopKeys = [];

        $seen = [Identifier::fold($start) => true];
        $queue = [[$start, 0]];
        $found = [];
        $expanded = 0;

        while ($queue !== []) {
            [$node, $at] = array_shift($queue);

            if ($at >= $depth || $expanded >= $this->maxNodes) {
                continue;
            }

            $expanded++;

            foreach ($this->edgesFrom($node) as $edge) {
                $key = Identifier::fold($edge->to);

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $found[] = [$at + 1, $edge];
                $queue[] = [$edge->to, $at + 1];
            }
        }

        return $found;
    }

    /**
     * @return list<Stop>
     */
    public function stops(): array
    {
        return $this->stops;
    }

    /**
     * What the walk had to say about itself: which bounds it applied, and where.
     *
     * Distinct from the stops. A stop is a place in the code the walk did not go; a note is a
     * decision the walk made — following twelve implementations of an interface and not the
     * other seventy-eight. Both have to reach the answer, because a bound nobody is told about
     * is indistinguishable from an absence.
     *
     * @return list<string>
     */
    public function notes(): array
    {
        return $this->framework instanceof LaravelEdges ? $this->framework->notes() : [];
    }

    /**
     * Every way out of one declaration: the calls it makes, plus the framework edges that
     * continue somewhere the call site does not name.
     *
     * @return list<Edge>
     */
    public function edgesFrom(string $node): array
    {
        $body = $this->code->body($node);

        if (! $body instanceof Body) {
            $miss = $this->code->miss($node);

            // A dead end the framework layer already went around is not a hole in the answer.
            // `Reporting::build()` has no body — the facade forwards it — and the walk has
            // already been given the edge to the class that does run, so listing the forward as
            // an edge that could not be followed would be reporting a gap that was filled.
            if ($miss->isGap && ! isset($this->rerouted[Identifier::fold($node)])) {
                $this->stop(new Stop($node, Stop::UNREADABLE, '', 0, 0, $miss->reason, $node));
            }

            return [];
        }

        if (! $this->throughDependencies && CodeEdges::isDependency($body->path)) {
            $this->stop(new Stop($node, Stop::DEPENDENCY, $body->path, $body->node->getStartLine(), 1, 'it is a dependency; --through-vendor walks past this', $body->fqn));

            return [];
        }

        $framework = $this->framework instanceof LaravelEdges
            ? [...$this->framework->dispatches($node, $body), ...$this->framework->resolutions($node, $body)]
            : [];

        // Keyed by the exact call each framework edge came from, not by its line: two calls on
        // one line are two different facts, and a dispatch does not vouch for an unresolvable
        // receiver written inside its own argument.
        $covered = [];

        foreach ($framework as $edge) {
            $covered[$edge->path.':'.$edge->line.':'.$edge->column] = true;
        }

        $edges = [];

        foreach ($this->code->callsFrom($body) as $call) {
            // The framework layer already answered for this very call: a call on
            // `app(Reader::class)` is typed as `Reader` now, but the binding edge beside it says
            // where it actually lands, and following both would make one step two.
            if (isset($covered[$call->path.':'.$call->line.':'.$call->column])) {
                if ($call->target !== null) {
                    $this->rerouted[Identifier::fold($call->target)] = true;
                }

                continue;
            }

            if ($call->target === null) {
                $this->stop(new Stop($node, Stop::UNRESOLVED, $call->path, $call->line, $call->column, $call->reason, $call->snippet));

                continue;
            }

            $edges[] = $this->edgeFor($node, $call);
            $indirections = $this->framework instanceof LaravelEdges ? $this->framework->indirections($node, $call) : [];

            if ($indirections !== []) {
                $this->rerouted[Identifier::fold($call->target)] = true;
            }

            foreach ($indirections as $edge) {
                $edges[] = $edge;
            }

            foreach ($this->framework instanceof LaravelEdges ? $this->framework->observed($node, $call) : [] as $edge) {
                $edges[] = $edge;
            }
        }

        return [...$edges, ...$framework];
    }

    private function edgeFor(string $from, OutgoingCall $call): Edge
    {
        return new Edge(
            from: $from,
            to: (string) $call->target,
            kind: $call->kind,
            path: $call->path,
            line: $call->line,
            column: $call->column,
            confidence: $call->confidence,
            reason: $call->reason,
        );
    }

    private function stop(Stop $stop): void
    {
        if (! isset($this->stopKeys[$stop->key()])) {
            $this->stopKeys[$stop->key()] = true;
            $this->stops[] = $stop;
        }
    }

    /**
     * How the walk got to a node, back to where it started.
     *
     * `$arrivedBy` is a tree and not a graph: a key is written exactly once, when the node it
     * names is first enqueued, and a node already enqueued is never enqueued again. So walking
     * the parent links terminates at the start without needing a guard against cycles — and the
     * arriving edge is appended by the caller rather than recorded here, which is what keeps
     * that invariant true where the destination is found.
     *
     * @param  array<string, Edge>  $arrivedBy
     * @return list<Edge>
     */
    private function trace(array $arrivedBy, string $node): array
    {
        $path = [];
        $current = $node;

        while (($edge = $arrivedBy[Identifier::fold($current)] ?? null) instanceof Edge) {
            array_unshift($path, $edge);
            $current = $edge->from;
        }

        return $path;
    }
}

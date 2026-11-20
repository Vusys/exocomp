<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Graph;

use Closure;
use PhpParser\Node;
use PhpParser\NodeFinder;
use Vusys\Exocomp\Analysis\Analyser;
use Vusys\Exocomp\Analysis\LiteralValue;
use Vusys\Exocomp\Framework\Laravel\Binding;
use Vusys\Exocomp\Framework\Laravel\ContainerReader;
use Vusys\Exocomp\Framework\Laravel\Dispatched;
use Vusys\Exocomp\Framework\Laravel\DispatchEdge;
use Vusys\Exocomp\Framework\Laravel\DispatchReader;
use Vusys\Exocomp\Framework\Laravel\FacadeResolver;
use Vusys\Exocomp\Framework\Laravel\LaravelProfile;
use Vusys\Exocomp\Framework\Laravel\ObserverReader;
use Vusys\Exocomp\Index\IndexStore;
use Vusys\Exocomp\Index\SymbolGraph;
use Vusys\Exocomp\Index\SymbolRecord;
use Vusys\Exocomp\Inference\OutgoingCall;
use Vusys\Exocomp\Project\ClassSource;
use Vusys\Exocomp\Project\LoadedClass;
use Vusys\Exocomp\Project\Project;
use Vusys\Exocomp\Symbol\Confidence;
use Vusys\Exocomp\Symbol\Identifier;
use Vusys\Exocomp\Symbol\SymbolKind;

/**
 * The edges the language cannot see.
 *
 * A call graph built from PHP alone is disconnected at every joint that matters in a framework
 * application, and the disconnections are not evenly spread — they sit precisely where control
 * leaves one layer for another. A controller dispatches a job and the trail stops. A service
 * takes an interface and the trail stops. A facade is called and the trail stops three hops
 * short of the class that runs. Each one of those is a place a reader currently opens four
 * files by hand.
 *
 * Every edge here is derived by reading source, and each carries the confidence its derivation
 * earns rather than the confidence a tidy answer would like. A job handling itself is a fact
 * about a class. A container binding is a fact about a provider that may be conditional. An
 * interface expanded to its implementations is a guess with a good reason behind it, and the
 * walk says so.
 */
final class LaravelEdges
{
    /**
     * How many implementations of one interface a walk will follow before it says it stopped.
     *
     * A widely implemented interface is a fan-out, not a path, and following ninety of them
     * turns a bounded question into a corpus sweep. The cap is disclosed when it bites, which
     * is the difference between a bounded answer and a wrong one.
     */
    public const int IMPLEMENTATION_FANOUT = 12;

    /** @var array<string, list<DispatchEdge>> */
    private array $receivers = [];

    /** @var array<string, list<DispatchEdge>> */
    private array $observers = [];

    /** @var ?array<string, true> */
    private ?array $facadesInCorpus = null;

    private ?ContainerReader $container = null;

    private bool $containerRead = false;

    private ?FacadeResolver $facades = null;

    /** @var list<string> */
    private array $notes = [];

    /**
     * @param  Closure(): ?ContainerReader  $openContainer  read once, and only if a walk
     *                                                      actually crosses an interface or a
     *                                                      facade
     */
    public function __construct(
        private readonly Project $project,
        private readonly SymbolGraph $graph,
        private readonly ClassSource $source,
        private readonly IndexStore $store,
        private readonly Closure $openContainer,
        private readonly int $fanout = self::IMPLEMENTATION_FANOUT,
    ) {}

    /**
     * Where the work sent from this declaration continues.
     *
     * @return list<Edge>
     */
    public function dispatches(string $from, Body $body): array
    {
        $edges = [];

        foreach (Dispatched::in([$body->node]) as $site) {
            foreach ($this->receiversOf($site->class) as $receiver) {
                $target = $this->entryPoint($receiver);

                if ($target === null) {
                    continue;
                }

                $edges[] = new Edge(
                    from: $from,
                    to: $target,
                    kind: $receiver->kind === 'handler' ? 'dispatch' : 'listens',
                    path: $body->path,
                    line: $site->line,
                    column: $this->columnAt($body, $site->position),
                    confidence: Confidence::weakest($site->confidence, $receiver->confidence),
                    reason: trim(sprintf('%s sends %s; %s', $site->sender, Dispatched::shortName($site->class), $receiver->note ?? 'received here')),
                );
            }
        }

        return $edges;
    }

    /**
     * Classes pulled out of the container by name: `app(Reader::class)`, `resolve(…)`,
     * `$this->app->make(…)`.
     *
     * The call itself resolves to nothing — the container's `make()` is typed `mixed`, and it
     * has to be — so without this the class named right there in the argument is not reachable
     * at all, and neither is the method being called on it one character later.
     *
     * @return list<Edge>
     */
    public function resolutions(string $from, Body $body): array
    {
        $edges = [];
        $resolutions = $this->containerArguments($body);
        $called = [];

        foreach (new NodeFinder()->findInstanceOf([$body->node], Node\Expr\MethodCall::class) as $call) {
            $id = spl_object_id($call->var);

            if (! isset($resolutions[$id]) || ! $call->name instanceof Node\Identifier) {
                continue;
            }

            $called[$id] = true;
            $edges[] = $this->resolution($from, $body, $resolutions[$id], $call->name->toString(), $call->getStartLine(), $call->getStartFilePos());
        }

        foreach ($resolutions as $id => $abstract) {
            if (! isset($called[$id])) {
                $edges[] = $this->resolution($from, $body, $abstract, '__construct', $abstract[1], $abstract[2]);
            }
        }

        return $edges;
    }

    /**
     * @param  array{string, int, int}  $abstract
     */
    private function resolution(string $from, Body $body, array $abstract, string $member, int $line, int $position): Edge
    {
        [$name] = $abstract;
        $concrete = $this->boundConcrete($name);

        return new Edge(
            from: $from,
            to: ($concrete ?? $name).'::'.$member,
            kind: 'binding',
            path: $body->path,
            line: $line,
            column: $this->columnAt($body, $position),
            confidence: $concrete === null ? Confidence::Resolved : Confidence::Inferred,
            reason: $concrete === null
                ? 'resolved out of the container by class name'
                : sprintf('resolved out of the container, where %s is bound to it', Dispatched::shortName($name)),
        );
    }

    /**
     * The classes a call could actually land on when the name at the call site is not the name
     * of the code that runs.
     *
     * @return list<Edge>
     */
    public function indirections(string $from, OutgoingCall $call): array
    {
        if ($call->target === null || ! str_contains($call->target, '::')) {
            return [];
        }

        [$class, $member] = explode('::', $call->target, 2);

        $facade = $this->throughFacade($from, $class, $member, $call);

        if ($facade !== []) {
            return $facade;
        }

        return $this->throughContainer($from, $class, $member, $call);
    }

    /**
     * The observer methods a model write runs. `$order->save()` lands in the framework, but the
     * framework then fires `saving` and `saved` on every observer of `Order`, and that code is
     * the application's. An event the write always fires is as certain as the registration; one
     * it fires only sometimes, such as `created` from `save()`, is `inferred`.
     *
     * @return list<Edge>
     */
    public function observed(string $from, OutgoingCall $call): array
    {
        if ($call->target === null || ! str_contains($call->target, '::')) {
            return [];
        }

        // The receiver, not the declaring class: `$order->save()` lands in the framework's
        // `Model::save`, but the events it fires are `Order`'s.
        [$declaring, $member] = explode('::', $call->target, 2);
        $model = $call->receiver ?? $declaring;
        $events = ObserverReader::EVENTS[strtolower($member)] ?? null;

        if ($events === null) {
            return [];
        }

        $edges = [];

        foreach ($this->observersOf($model) as $observer) {
            $loaded = $this->source->load((string) $observer->counterpart);

            if (! $loaded instanceof LoadedClass) {
                continue;
            }

            foreach ([...$events[0], ...$events[1]] as $event) {
                if (! $loaded->node->getMethod($event) instanceof Node\Stmt\ClassMethod) {
                    continue;
                }

                $always = in_array($event, $events[0], true);

                $edges[] = new Edge(
                    from: $from,
                    to: $loaded->fqn.'::'.$event,
                    kind: 'observed',
                    path: $call->path,
                    line: $call->line,
                    column: $call->column,
                    confidence: Confidence::weakest($call->confidence, $always ? $observer->confidence : Confidence::Inferred),
                    reason: sprintf('%s() fires %s%s; %s', $member, $event, $always ? '' : ' for some models', $observer->note ?? 'observed here'),
                );
            }
        }

        return $edges;
    }

    /**
     * @return list<string>
     */
    public function notes(): array
    {
        return array_values(array_unique($this->notes));
    }

    /**
     * @return list<Edge>
     */
    private function throughFacade(string $from, string $class, string $member, OutgoingCall $call): array
    {
        if (! $this->facadeLike($class)) {
            return [];
        }

        $resolver = $this->facadeResolver();

        if (! $resolver instanceof FacadeResolver) {
            return [];
        }

        $loaded = $this->source->load($class);

        if (! $loaded instanceof LoadedClass) {
            return [];
        }

        // A facade that declares the method itself runs its own code — `Storage::fake()` is on
        // the facade, not on the manager behind it — and reporting the forwarded edge there
        // would send the walk into a class the call never reaches.
        if ($loaded->node->getMethod($member) instanceof Node\Stmt\ClassMethod) {
            return [];
        }

        $accessor = $resolver->accessor($loaded);

        if ($accessor === null) {
            return [];
        }

        [$concrete, $confidence, $reason] = $resolver->concrete($accessor[0], $accessor[1]);

        if ($concrete === null) {
            return [];
        }

        return [new Edge(
            from: $from,
            to: $concrete.'::'.$member,
            kind: 'facade',
            path: $call->path,
            line: $call->line,
            column: $call->column,
            confidence: $confidence,
            reason: $reason,
        )];
    }

    /**
     * @return list<Edge>
     */
    private function throughContainer(string $from, string $class, string $member, OutgoingCall $call): array
    {
        $record = $this->graph->classLike($class);

        if ($record instanceof SymbolRecord) {
            // The index already knows what kind of thing this is and whether it is abstract.
            // Reading the class's source to find that out would be a file parse for every
            // distinct receiver type in the walk, which on a real corpus is most of the cost of
            // the answer for a fact already recorded.
            $expandable = $record->kind === SymbolKind::InterfaceType || str_contains($record->modifiers, 'abstract');

            if (! $expandable) {
                return [];
            }

            $bound = $this->boundConcrete($class);
        } else {
            // Not in the corpus, so it is a dependency's own interface. Those are worth
            // following only when this application binds one, which is a hash lookup rather
            // than a parse.
            $bound = $this->boundConcrete($class);

            if ($bound === null) {
                return [];
            }
        }

        $edges = [];

        if ($bound !== null) {
            $edges[] = new Edge(
                from: $from,
                to: $bound.'::'.$member,
                kind: 'binding',
                path: $call->path,
                line: $call->line,
                column: $call->column,
                confidence: Confidence::Inferred,
                reason: sprintf('%s is bound to it in the container', Dispatched::shortName($class)),
            );
        }

        $implementors = [
            ...$this->graph->implementors($class, true),
            ...$this->graph->subclasses($class, true),
        ];

        $kept = 0;

        foreach ($implementors as $implementor) {
            if ($bound !== null && Identifier::fold($implementor->fqn) === Identifier::fold($bound)) {
                continue;
            }

            if ($kept >= $this->fanout) {
                $this->notes[] = sprintf(
                    '%s has %d implementations in the corpus; the walk followed %d of them and stopped. Ask about one directly to follow the rest.',
                    $class,
                    count($implementors),
                    $this->fanout,
                );

                break;
            }

            $kept++;
            $edges[] = new Edge(
                from: $from,
                to: $implementor->fqn.'::'.$member,
                kind: 'implementation',
                path: $call->path,
                line: $call->line,
                column: $call->column,
                confidence: Confidence::Inferred,
                reason: sprintf('implements %s; nothing at the call site says which implementation runs', Dispatched::shortName($class)),
            );
        }

        return $edges;
    }

    /**
     * Whether a name could be a facade, decided without opening a file.
     *
     * Two populations and neither needs a parse: every facade the corpus declares is a
     * descendant of `Facade` in the index's own edge table, and the framework's own facades all
     * sit under one namespace. Asking the source instead — "load this class, walk its parents"
     * — costs a file parse per distinct static receiver in the walk, and static receivers are
     * most of the call sites in a framework application.
     */
    private function facadeLike(string $class): bool
    {
        if (str_starts_with($class, FacadeResolver::FACADE_NAMESPACE)) {
            return true;
        }

        return isset($this->declaredFacades()[Identifier::fold($class)]);
    }

    /**
     * @return array<string, true>
     */
    private function declaredFacades(): array
    {
        if ($this->facadesInCorpus !== null) {
            return $this->facadesInCorpus;
        }

        $found = [];

        foreach ($this->graph->subclasses(LaravelProfile::FACADE, true) as $record) {
            $found[Identifier::fold($record->fqn)] = true;
        }

        return $this->facadesInCorpus = $found;
    }

    private function facadeResolver(): ?FacadeResolver
    {
        if ($this->facades instanceof FacadeResolver) {
            return $this->facades;
        }

        $container = $this->containerReader();

        return $container instanceof ContainerReader
            ? $this->facades = new FacadeResolver($this->source, $container)
            : null;
    }

    private function containerReader(): ?ContainerReader
    {
        if (! $this->containerRead) {
            $this->containerRead = true;
            $this->container = ($this->openContainer)();
        }

        return $this->container;
    }

    private function boundConcrete(string $abstract): ?string
    {
        $binding = $this->containerReader()?->concreteFor($abstract);

        return $binding instanceof Binding ? $binding->concrete : null;
    }

    /**
     * @return list<DispatchEdge>
     */
    private function observersOf(string $model): array
    {
        $key = Identifier::fold($model);

        if (isset($this->observers[$key])) {
            return $this->observers[$key];
        }

        // Only a file that names the model beside one of the three registration spellings can
        // register an observer for it. A model's short name alone is in hundreds of files.
        $short = Dispatched::shortName($model);
        $paths = [];

        foreach (['observe', 'observers', 'ObservedBy'] as $spelling) {
            $paths = [...$paths, ...$this->store->pathsMentioning($this->project->root, [$short, $spelling])];
        }

        $paths = array_values(array_unique($paths));

        return $this->observers[$key] = new ObserverReader()
            ->read($model, $paths, fn (string $path): string => $this->project->path($path));
    }

    /**
     * @return list<DispatchEdge>
     */
    private function receiversOf(string $class): array
    {
        $key = Identifier::fold($class);

        if (isset($this->receivers[$key])) {
            return $this->receivers[$key];
        }

        // A class that handles itself is a queued job, and a queued job is not also listened
        // for. Establishing that by scanning every file that mentions its name — which is what
        // finding listeners requires — costs more than the answer is worth, and the answer is
        // already known: the class's own `handle()` is the receiver.
        $loaded = $this->source->load($class);
        $handle = $loaded?->node->getMethod('handle');

        if ($loaded instanceof LoadedClass && $handle instanceof Node\Stmt\ClassMethod) {
            return $this->receivers[$key] = [new DispatchEdge(
                'handler',
                $class,
                $loaded->fqn.'::handle',
                $loaded->path,
                $handle->getStartLine(),
                Confidence::Resolved,
                'the job handles itself',
            )];
        }

        $paths = $this->store->pathsMentioning($this->project->root, [Dispatched::shortName($class)]);

        return $this->receivers[$key] = new DispatchReader($this->source)
            ->read($class, $paths, fn (string $path): string => $this->project->path($path))
            ->receivers();
    }

    /**
     * The address a receiver is actually entered at: a job's own `handle`, a listener's
     * `handle` or `__invoke`.
     */
    private function entryPoint(DispatchEdge $receiver): ?string
    {
        $counterpart = $receiver->counterpart;

        if ($counterpart === null) {
            return null;
        }

        if (str_contains($counterpart, '::')) {
            return $counterpart;
        }

        $loaded = $this->source->load($counterpart);

        if (! $loaded instanceof LoadedClass) {
            return null;
        }

        foreach (['handle', '__invoke'] as $entry) {
            if ($loaded->node->getMethod($entry) instanceof Node\Stmt\ClassMethod) {
                return $loaded->fqn.'::'.$entry;
            }
        }

        return null;
    }

    /**
     * Every expression in a body that pulls something out of the container, keyed by the node
     * it is, so a `->method()` written straight onto one can be matched back to it.
     *
     * @return array<int, array{string, int, int}>
     */
    private function containerArguments(Body $body): array
    {
        $found = [];

        foreach (new NodeFinder()->findInstanceOf([$body->node], Node\Expr\FuncCall::class) as $call) {
            if (! $call->name instanceof Node\Name) {
                continue;
            }

            if (! Analyser::isGlobalFunction($call->name, 'app') && ! Analyser::isGlobalFunction($call->name, 'resolve')) {
                continue;
            }

            $class = $this->firstClassArgument($call);

            if ($class !== null) {
                $found[spl_object_id($call)] = [$class, $call->getStartLine(), $call->getStartFilePos()];
            }
        }

        foreach (new NodeFinder()->findInstanceOf([$body->node], Node\Expr\MethodCall::class) as $call) {
            if (! $call->name instanceof Node\Identifier || strcasecmp($call->name->toString(), 'make') !== 0) {
                continue;
            }

            $class = $this->firstClassArgument($call);

            if ($class !== null) {
                $found[spl_object_id($call)] = [$class, $call->getStartLine(), $call->getStartFilePos()];
            }
        }

        return $found;
    }

    /**
     * A first argument that is a class name — `Reader::class` or the literal string of one.
     */
    private function firstClassArgument(Node\Expr\FuncCall|Node\Expr\MethodCall $call): ?string
    {
        $argument = $call->getArgs()[0]->value ?? null;
        $class = $argument instanceof Node\Expr ? LiteralValue::className($argument) : null;

        return $class !== null && str_contains($class, '\\') ? $class : null;
    }

    /**
     * The column a byte offset sits at, so a framework edge can be matched back to the exact
     * call it came from. Two calls on one line are two different facts, and matching by line
     * alone would let an edge that was filled hide a gap that was not.
     */
    private function columnAt(Body $body, int $position): int
    {
        return $position < 0 ? 1 : $body->analysis->file->columnAt($position);
    }
}

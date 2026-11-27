<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Framework\Laravel;

use PhpParser\Node;
use PhpParser\NodeFinder;
use Vusys\Exocomp\Analysis\Analyser;
use Vusys\Exocomp\Analysis\LiteralValue;
use Vusys\Exocomp\Project\ClassSource;
use Vusys\Exocomp\Project\LoadedClass;
use Vusys\Exocomp\Source\ParseFailure;
use Vusys\Exocomp\Source\SourceFile;
use Vusys\Exocomp\Symbol\Confidence;
use Vusys\Exocomp\Symbol\Identifier;

/**
 * Where a job or event is sent, and what receives it.
 *
 * This is the edge that makes a framework call graph disconnected. `dispatch(new ProcessOrder)`
 * continues execution in a different class, in a different process, possibly minutes later, and
 * nothing at the call site names the code that runs — `calls-from` reports it as unresolved and
 * is right to. The 65 measured searches for jobs, events and listeners were people rebuilding
 * this edge by hand each time.
 *
 * Three ways a receiver is established, and they are not equally certain. A job handles itself,
 * which is a fact about the class. A listener registered in a provider's `$listen` array is a
 * declaration, read literally. A listener discovered by the type of its `handle()` parameter is
 * the framework's own convention, applied at boot — true in practice and inferred here, because
 * whether discovery is enabled is not visible from the source.
 */
final class DispatchReader
{
    /** @var list<DispatchEdge> */
    private array $sites = [];

    /** @var list<DispatchEdge> */
    private array $receivers = [];

    private int $filesScanned = 0;

    public function __construct(
        private readonly ClassSource $source,
        private readonly Analyser $analyser = new Analyser,
    ) {}

    /**
     * @param  list<string>  $paths  display paths of every file that mentions the subject
     * @param  callable(string): string  $absolute
     */
    public function read(string $subject, array $paths, callable $absolute): self
    {
        $short = $this->shortName($subject);

        foreach ($paths as $path) {
            $file = $absolute($path);

            if (! is_file($file)) {
                continue;
            }

            try {
                [, $statements] = $this->analyser->analyseWithAst(SourceFile::fromPath($file, $path));
            } catch (ParseFailure) {
                continue;
            }

            $this->filesScanned++;
            $this->scanForSends($statements, $subject, $path);
            $this->scanForListenerRegistrations($statements, $subject, $path);
            $this->scanForTypedHandlers($statements, $subject, $path, $short);
        }

        $this->ownHandler($subject);

        return $this;
    }

    /**
     * Sends whose argument is an object of a type not decided here, in the files given: any of
     * them may send the subject, so they are what the count of sites is a lower bound against.
     *
     * @param  list<string>  $paths
     * @param  callable(string): string  $absolute
     * @return list<DispatchEdge>
     */
    public function undecided(array $paths, callable $absolute): array
    {
        $found = [];

        foreach ($paths as $path) {
            $file = $absolute($path);

            if (! is_file($file)) {
                continue;
            }

            try {
                [, $statements] = $this->analyser->analyseWithAst(SourceFile::fromPath($file, $path));
            } catch (ParseFailure) {
                continue;
            }

            foreach (Dispatched::in($statements) as $site) {
                if ($site->class === '') {
                    $found[] = new DispatchEdge('undecided', '', null, $path, $site->line, Confidence::Textual, sprintf('%s sends something whose type is not known here', $site->sender));
                }
            }
        }

        return $found;
    }

    /**
     * @return list<DispatchEdge>
     */
    public function sites(): array
    {
        return $this->sites;
    }

    /**
     * @return list<DispatchEdge>
     */
    public function receivers(): array
    {
        // A listener can be both declared in `$listen` and discoverable by its parameter type,
        // and reporting it twice would read as two listeners. The declaration wins: it is a
        // fact about the source, where discovery is a convention that may or may not be on.
        $best = [];

        foreach ($this->receivers as $edge) {
            $key = Identifier::fold((string) $edge->counterpart);
            $existing = $best[$key] ?? null;

            if (! $existing instanceof DispatchEdge || $this->rank($edge) > $this->rank($existing)) {
                $best[$key] = $edge;
            }
        }

        return array_values($best);
    }

    private function rank(DispatchEdge $edge): int
    {
        return match ($edge->confidence) {
            Confidence::Resolved => 2,
            Confidence::Inferred => 1,
            Confidence::Textual => 0,
        };
    }

    public function filesScanned(): int
    {
        return $this->filesScanned;
    }

    /**
     * @param  array<int, Node>  $statements
     */
    private function scanForSends(array $statements, string $subject, string $path): void
    {
        foreach (Dispatched::in($statements) as $site) {
            if (Identifier::fold($site->class) !== Identifier::fold($subject)) {
                continue;
            }

            $this->sites[] = new DispatchEdge('sent', $subject, $site->sender, $path, $site->line, $site->confidence, $site->note);
        }
    }

    /**
     * `$listen = [Event::class => [Listener::class, …]]` on a provider, and `Event::listen(…)`.
     *
     * @param  array<int, Node>  $statements
     */
    private function scanForListenerRegistrations(array $statements, string $subject, string $path): void
    {
        foreach (new NodeFinder()->findInstanceOf($statements, Node\Stmt\Property::class) as $property) {
            foreach ($property->props as $item) {
                if ($item->name->toString() !== 'listen' || ! $item->default instanceof Node\Expr\Array_) {
                    continue;
                }

                foreach ($item->default->items as $entry) {
                    if (! $entry->key instanceof Node\Expr) {
                        continue;
                    }

                    $event = LiteralValue::className($entry->key);

                    if ($event === null || Identifier::fold($event) !== Identifier::fold($subject)) {
                        continue;
                    }

                    foreach ($this->classList($entry->value) as $listener) {
                        $this->receivers[] = new DispatchEdge('listener', $subject, $listener, $path, $entry->getStartLine(), Confidence::Resolved, 'registered in $listen');
                    }
                }
            }
        }

        foreach (new NodeFinder()->findInstanceOf($statements, Node\Expr\StaticCall::class) as $call) {
            if (! $call->class instanceof Node\Name || ! $call->name instanceof Node\Identifier || strcasecmp($call->name->toString(), 'listen') !== 0) {
                continue;
            }

            $arguments = Analyser::arguments($call);
            $first = $arguments[0]->value ?? null;

            // `Event::listen(function (OrderShipped $e) { … })`: the closure's parameter names the event.
            if ($first instanceof Node\Expr\Closure || $first instanceof Node\Expr\ArrowFunction) {
                if (in_array(Identifier::fold($subject), array_map(Identifier::fold(...), $this->parameterClasses($first->params[0]->type ?? null)), true)) {
                    $this->receivers[] = new DispatchEdge('listener', $subject, null, $path, $call->getStartLine(), Confidence::Resolved, 'a closure registered by Event::listen, typed by its parameter');
                }

                continue;
            }

            $event = $first instanceof Node\Expr ? LiteralValue::className($first) : null;

            if ($event === null || Identifier::fold($event) !== Identifier::fold($subject)) {
                continue;
            }

            $second = $arguments[1]->value ?? null;
            $listener = $second instanceof Node\Expr ? LiteralValue::className($second) : null;

            // `[AuditShipment::class, 'handle']`, an array callable.
            if ($listener === null && $second instanceof Node\Expr\Array_ && count($second->items) === 2) {
                $listener = LiteralValue::className($second->items[0]->value);
            }

            $this->receivers[] = new DispatchEdge(
                'listener',
                $subject,
                $listener,
                $path,
                $call->getStartLine(),
                $listener === null ? Confidence::Textual : Confidence::Resolved,
                $listener === null ? 'registered as a closure' : 'registered by Event::listen',
            );
        }

        $this->scanForSubscribers($statements, $subject, $path);
    }

    /**
     * The classes a parameter type names: one, or each side of a union.
     *
     * @return list<string>
     */
    private function parameterClasses(?Node $type): array
    {
        return match (true) {
            $type instanceof Node\Name => [Analyser::resolvedName($type)],
            $type instanceof Node\NullableType => $this->parameterClasses($type->type),
            $type instanceof Node\UnionType => array_merge(...array_map($this->parameterClasses(...), $type->types)),
            default => [],
        };
    }

    /**
     * Subscribers, registered in a provider's `$subscribe` or with `Event::subscribe()`: each
     * one's `subscribe()` returns `[Event::class => 'method']` or calls `$events->listen()`.
     *
     * @param  array<int, Node>  $statements
     */
    private function scanForSubscribers(array $statements, string $subject, string $path): void
    {
        $subscribers = [];

        foreach (new NodeFinder()->findInstanceOf($statements, Node\Stmt\Property::class) as $property) {
            foreach ($property->props as $item) {
                if ($item->name->toString() === 'subscribe' && $item->default instanceof Node\Expr) {
                    $subscribers = [...$subscribers, ...$this->classList($item->default)];
                }
            }
        }

        foreach (new NodeFinder()->findInstanceOf($statements, Node\Expr\StaticCall::class) as $call) {
            if ($call->name instanceof Node\Identifier && strcasecmp($call->name->toString(), 'subscribe') === 0
                && ($first = Analyser::arguments($call)[0]->value ?? null) instanceof Node\Expr) {
                $subscribers = [...$subscribers, ...$this->classList($first)];
            }
        }

        foreach (array_unique($subscribers) as $subscriber) {
            $loaded = $this->source->load($subscriber);
            $method = $loaded?->node->getMethod('subscribe');

            if (! $loaded instanceof LoadedClass || ! $method instanceof Node\Stmt\ClassMethod) {
                continue;
            }

            $path = $loaded->path;

            foreach (new NodeFinder()->findInstanceOf($method->stmts ?? [], Node\Stmt\Return_::class) as $return) {
                foreach ($return->expr instanceof Node\Expr\Array_ ? $return->expr->items : [] as $entry) {
                    if ($entry->key instanceof Node\Expr && Identifier::fold((string) LiteralValue::className($entry->key)) === Identifier::fold($subject)) {
                        $this->receivers[] = new DispatchEdge('listener', $subject, $subscriber, $path, $entry->getStartLine(), Confidence::Resolved, 'a subscriber: its subscribe() maps the event to a method');
                    }
                }
            }

            foreach (new NodeFinder()->findInstanceOf($method->stmts ?? [], Node\Expr\MethodCall::class) as $listen) {
                if ($listen->name instanceof Node\Identifier && strcasecmp($listen->name->toString(), 'listen') === 0
                    && ($event = Analyser::arguments($listen)[0]->value ?? null) instanceof Node\Expr
                    && Identifier::fold((string) LiteralValue::className($event)) === Identifier::fold($subject)) {
                    $this->receivers[] = new DispatchEdge('listener', $subject, $subscriber, $path, $listen->getStartLine(), Confidence::Resolved, 'a subscriber: its subscribe() listens for the event');
                }
            }
        }
    }

    /**
     * A listener the framework discovers by the type of its `handle()` parameter.
     *
     * @param  array<int, Node>  $statements
     */
    private function scanForTypedHandlers(array $statements, string $subject, string $path, string $short): void
    {
        foreach (new NodeFinder()->findInstanceOf($statements, Node\Stmt\Class_::class) as $class) {
            foreach ($class->getMethods() as $method) {
                $name = strtolower($method->name->toString());

                if ($name !== 'handle' && $name !== '__invoke') {
                    continue;
                }

                // A union, `OrderShipped|OrderCancelled $e`, is discovered for each of its types.
                if (! in_array(Identifier::fold($subject), array_map(Identifier::fold(...), $this->parameterClasses($method->params[0]->type ?? null)), true)) {
                    continue;
                }

                $owner = $class->namespacedName?->toString() ?? $class->name?->toString() ?? $short;

                if (Identifier::fold($owner) === Identifier::fold($subject)) {
                    continue;
                }

                $this->receivers[] = new DispatchEdge(
                    'listener',
                    $subject,
                    $owner,
                    $path,
                    $method->getStartLine(),
                    Confidence::Inferred,
                    'takes the event by type; the framework discovers listeners this way, if discovery is enabled',
                );
            }
        }
    }

    /**
     * A queued job handles itself. That is a fact about the class rather than a registration,
     * and it is the receiver someone chasing `dispatch(new ProcessOrder)` is after.
     */
    private function ownHandler(string $subject): void
    {
        $class = $this->source->load($subject);

        if (! $class instanceof LoadedClass) {
            return;
        }

        $handle = $class->node->getMethod('handle');

        if ($handle instanceof Node\Stmt\ClassMethod) {
            $this->receivers[] = new DispatchEdge('handler', $subject, $subject.'::handle', $class->path, $handle->getStartLine(), Confidence::Resolved, 'the job handles itself');
        }
    }

    /**
     * @return list<string>
     */
    private function classList(Node\Expr $expression): array
    {
        $single = LiteralValue::className($expression);

        if ($single !== null) {
            return [$single];
        }

        if (! $expression instanceof Node\Expr\Array_) {
            return [];
        }

        $classes = [];

        foreach ($expression->items as $item) {
            $class = LiteralValue::className($item->value);

            if ($class !== null) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    private function shortName(string $fqn): string
    {
        return Dispatched::shortName($fqn);
    }
}

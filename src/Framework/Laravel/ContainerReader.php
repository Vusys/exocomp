<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Framework\Laravel;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\NodeFinder;
use Vusys\Exocomp\Analysis\Analyser;
use Vusys\Exocomp\Analysis\LiteralValue;
use Vusys\Exocomp\Index\SymbolGraph;
use Vusys\Exocomp\Index\SymbolRecord;
use Vusys\Exocomp\Project\ClassSource;
use Vusys\Exocomp\Project\LoadedClass;
use Vusys\Exocomp\Symbol\Confidence;

/**
 * Reads the container out of the service providers.
 *
 * Container bindings are where a language-only call graph comes apart: `app(Reader::class)`
 * returns something whose class is decided in a file the call site never mentions, and the 22
 * measured searches for bindings were people following that thread by hand.
 *
 * Providers are found by inheritance rather than by convention — anything extending
 * `ServiceProvider`, wherever it lives — because an application that keeps providers outside
 * `app/Providers` is not an unusual application.
 *
 * A binding whose concrete side is a closure is followed exactly one step: a closure whose
 * return is a single `new X` is that X, and anything more involved is declined. Guessing
 * further would produce the confident half-answer that makes a graph untrustworthy.
 */
final class ContainerReader
{
    /** @var list<string> */
    private const array BINDS = ['bind', 'bindif', 'singleton', 'singletonif', 'scoped', 'scopedif', 'instance'];

    /** @var list<Binding> */
    private array $bindings = [];

    /** @var list<Binding> */
    private array $declined = [];

    /** @var list<Binding> */
    private array $morphMap = [];

    /** @var list<string> */
    private array $notes = [];

    private int $providersRead = 0;

    public function __construct(
        private readonly SymbolGraph $graph,
        private readonly ClassSource $source,
    ) {}

    /**
     * @param  ?callable(string): list<ConfigEntry>  $config  resolves a config key to its
     *                                                        definitions, so a morph map given
     *                                                        as `config('morph-map')` can be
     *                                                        followed rather than declined
     */
    public function read(?callable $config = null): self
    {
        foreach ($this->providers() as $record) {
            $class = $this->source->load($record->fqn);

            if (! $class instanceof LoadedClass) {
                continue;
            }

            $this->providersRead++;
            $this->readBindings($class);
            $this->readAliases($class);
            $this->readContextual($class);
            $this->readMorphMap($class, $config);
        }

        return $this;
    }

    /**
     * @return list<Binding>
     */
    public function bindings(): array
    {
        return $this->bindings;
    }

    /**
     * @return list<Binding>
     */
    public function declined(): array
    {
        return $this->declined;
    }

    /**
     * @return list<Binding>
     */
    public function morphMap(): array
    {
        return $this->morphMap;
    }

    public function providersRead(): int
    {
        return $this->providersRead;
    }

    /**
     * @return list<string>
     */
    public function notes(): array
    {
        return [...$this->notes, ...$this->source->notes()];
    }

    /**
     * What an abstract name resolves to, if anything registered it.
     */
    public function concreteFor(string $abstract): ?Binding
    {
        foreach ($this->bindings as $binding) {
            if ($binding->context === null && strcasecmp($binding->abstract, $abstract) === 0) {
                return $binding;
            }
        }

        return null;
    }

    /**
     * @return list<SymbolRecord>
     */
    private function providers(): array
    {
        $found = $this->graph->subclasses(LaravelProfile::SERVICE_PROVIDER, recursive: true);

        if ($found === []) {
            $this->notes[] = 'no class in the indexed source roots extends '.LaravelProfile::SERVICE_PROVIDER;
        }

        return $found;
    }

    private function readBindings(LoadedClass $class): void
    {
        foreach ($this->containerCalls($class) as [$method, $arguments, $line]) {
            if (! in_array($method, self::BINDS, true)) {
                continue;
            }

            $abstract = $arguments === [] ? null : $this->name($arguments[0]->value);

            if ($abstract === null) {
                $this->declined[] = new Binding('?', null, $method, $class->path, $line, Confidence::Textual, 'the abstract is not named literally', $class->fqn);

                continue;
            }

            // `singleton(Foo::class)` with no second argument binds the class to itself.
            $second = $arguments[1]->value ?? null;

            if (! $second instanceof Expr) {
                $this->bindings[] = new Binding($abstract, $abstract, $method, $class->path, $line, Confidence::Resolved, 'bound to itself', $class->fqn);

                continue;
            }

            $this->recordConcrete($class, $abstract, $method, $second, $line);
        }
    }

    private function recordConcrete(LoadedClass $class, string $abstract, string $method, Expr $expression, int $line, ?string $context = null): void
    {
        $direct = $this->name($expression);

        if ($direct !== null) {
            $literal = $expression instanceof Expr\ClassConstFetch;

            $this->bindings[] = new Binding(
                $abstract,
                $direct,
                $method,
                $class->path,
                $line,
                $literal ? Confidence::Resolved : Confidence::Inferred,
                $literal ? null : 'named by a string rather than ::class',
                $class->fqn,
                $context,
            );

            return;
        }

        $constructed = $this->singleConstruction($expression);

        if ($constructed !== null) {
            $this->bindings[] = new Binding(
                $abstract,
                $constructed,
                $method,
                $class->path,
                $line,
                Confidence::Inferred,
                'the factory returns one `new`, so this is what it builds',
                $class->fqn,
                $context,
            );

            return;
        }

        $this->declined[] = new Binding(
            $abstract,
            null,
            $method,
            $class->path,
            $line,
            Confidence::Textual,
            'built by a factory that does more than construct one class',
            $class->fqn,
            $context,
        );
    }

    /**
     * A closure whose return is a single `new X`, and nothing cleverer. Following further would
     * mean evaluating the factory, which is where a static reader starts inventing answers.
     */
    private function singleConstruction(Expr $expression): ?string
    {
        if (! $expression instanceof Expr\Closure && ! $expression instanceof Expr\ArrowFunction) {
            return null;
        }

        $constructions = [];

        foreach (new NodeFinder()->findInstanceOf([$expression], Expr\New_::class) as $new) {
            if ($new->class instanceof Node\Name) {
                $constructions[Analyser::resolvedName($new->class)] = true;
            }
        }

        return count($constructions) === 1 ? array_key_first($constructions) : null;
    }

    private function readAliases(LoadedClass $class): void
    {
        foreach ($this->containerCalls($class) as [$method, $arguments, $line]) {
            if ($method !== 'alias' || count($arguments) < 2) {
                continue;
            }

            $abstract = $this->name($arguments[0]->value);
            $alias = LiteralValue::scalar($arguments[1]->value);

            if ($abstract === null || ! is_string($alias)) {
                continue;
            }

            $this->bindings[] = new Binding($alias, $abstract, 'alias', $class->path, $line, Confidence::Resolved, null, $class->fqn);
        }
    }

    /**
     * `$this->app->when(X::class)->needs(Y::class)->give(Z::class)`.
     *
     * A contextual binding is invisible from the consumer's side — X asks for Y and gets Z, and
     * nothing at either end says so. Reporting it alongside the plain bindings is the point.
     */
    private function readContextual(LoadedClass $class): void
    {
        foreach (new NodeFinder()->findInstanceOf([$class->node], Expr\MethodCall::class) as $call) {
            if (! $call->name instanceof Node\Identifier || strcasecmp($call->name->toString(), 'give') !== 0) {
                continue;
            }

            $needs = $call->var;

            if (! $needs instanceof Expr\MethodCall || ! $needs->name instanceof Node\Identifier || strcasecmp($needs->name->toString(), 'needs') !== 0) {
                continue;
            }

            $when = $needs->var;

            if (! $when instanceof Expr\MethodCall || ! $when->name instanceof Node\Identifier || strcasecmp($when->name->toString(), 'when') !== 0) {
                continue;
            }

            $context = Analyser::arguments($when)[0]->value ?? null;
            $abstract = Analyser::arguments($needs)[0]->value ?? null;
            $concrete = Analyser::arguments($call)[0]->value ?? null;

            if ($context === null || $abstract === null || $concrete === null) {
                continue;
            }

            $abstractName = $this->name($abstract);
            $contextName = $this->name($context);

            if ($abstractName === null || $contextName === null) {
                continue;
            }

            $this->recordConcrete($class, $abstractName, 'contextual', $concrete, $call->getStartLine(), $contextName);
        }
    }

    /**
     * @param  ?callable(string): list<ConfigEntry>  $config
     */
    private function readMorphMap(LoadedClass $class, ?callable $config): void
    {
        foreach (new NodeFinder()->findInstanceOf([$class->node], Expr\StaticCall::class) as $call) {
            if (! $call->class instanceof Node\Name || ! $call->name instanceof Node\Identifier) {
                continue;
            }

            $method = strtolower($call->name->toString());

            if (! in_array($method, ['morphmap', 'enforcemorphmap'], true)) {
                continue;
            }

            $argument = Analyser::arguments($call)[0]->value ?? null;

            if ($argument === null) {
                continue;
            }

            if ($argument instanceof Expr\Array_) {
                foreach (LiteralValue::pairs($argument) as $pair) {
                    $this->morphMap[] = new Binding($pair['key'], $pair['value'], 'morph', $class->path, $pair['line'], Confidence::Resolved, null, $class->fqn);
                }

                continue;
            }

            $this->morphMapFromConfig($class, $argument, $call->getStartLine(), $config);
        }
    }

    /**
     * `Relation::morphMap(config('morph-map'))` is a common spelling, and declining it would
     * leave the map empty on applications that use it — which is to say, on the ones with
     * enough polymorphic relations to need one.
     *
     * @param  ?callable(string): list<ConfigEntry>  $config
     */
    private function morphMapFromConfig(LoadedClass $class, Expr $argument, int $line, ?callable $config): void
    {
        $key = $this->configKey($argument);

        if ($key === null || $config === null) {
            $this->declined[] = new Binding('morph map', null, 'morph', $class->path, $line, Confidence::Textual, 'the map is built at runtime', $class->fqn);

            return;
        }

        $entries = $config($key);
        $found = 0;

        foreach ($entries as $entry) {
            if ($entry->value === null || ! str_starts_with($entry->key, $key.'.') || str_contains(substr($entry->key, strlen($key) + 1), '.')) {
                continue;
            }

            $found++;
            $this->morphMap[] = new Binding(
                substr($entry->key, strlen($key) + 1),
                $entry->value,
                'morph',
                $entry->path,
                $entry->line,
                Confidence::Inferred,
                sprintf('read from config("%s"), registered by %s', $key, $class->fqn),
                $class->fqn,
            );
        }

        if ($found === 0) {
            $this->declined[] = new Binding('morph map', null, 'morph', $class->path, $line, Confidence::Textual, sprintf('registered from config("%s"), which defines nothing', $key), $class->fqn);
        }
    }

    private function configKey(Expr $expression): ?string
    {
        if (! $expression instanceof Expr\FuncCall
            || ! $expression->name instanceof Node\Name
            || ! Analyser::isGlobalFunction($expression->name, 'config')) {
            return null;
        }

        $first = Analyser::arguments($expression)[0]->value ?? null;
        $key = $first === null ? null : LiteralValue::scalar($first);

        return is_string($key) ? $key : null;
    }

    /**
     * Every call made on the container, however it was reached — `$this->app->…`, `app()->…`
     * and `App::…` are the same registration written three ways.
     *
     * @return list<array{string, array<int, Node\Arg>, int}>
     */
    private function containerCalls(LoadedClass $class): array
    {
        $calls = [];

        foreach (new NodeFinder()->findInstanceOf([$class->node], Expr\MethodCall::class) as $call) {
            if ($call->name instanceof Node\Identifier && $this->isContainer($call->var)) {
                $calls[] = [strtolower($call->name->toString()), Analyser::arguments($call), $call->getStartLine()];
            }
        }

        foreach (new NodeFinder()->findInstanceOf([$class->node], Expr\StaticCall::class) as $call) {
            if ($call->class instanceof Node\Name && $call->name instanceof Node\Identifier) {
                $class_ = Analyser::resolvedName($call->class);

                if (str_ends_with($class_, '\\App') || $class_ === 'App') {
                    $calls[] = [strtolower($call->name->toString()), Analyser::arguments($call), $call->getStartLine()];
                }
            }
        }

        return $calls;
    }

    private function isContainer(Expr $receiver): bool
    {
        if ($receiver instanceof Expr\PropertyFetch
            && $receiver->var instanceof Expr\Variable
            && $receiver->var->name === 'this'
            && $receiver->name instanceof Node\Identifier) {
            return strcasecmp($receiver->name->toString(), 'app') === 0;
        }

        return $receiver instanceof Expr\FuncCall
            && $receiver->name instanceof Node\Name
            && Analyser::isGlobalFunction($receiver->name, 'app');
    }

    private function name(Expr $expression): ?string
    {
        return LiteralValue::className($expression) ?? (is_string($value = LiteralValue::scalar($expression)) ? $value : null);
    }
}

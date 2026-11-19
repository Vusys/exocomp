<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Inference;

use PhpParser\Node;

/**
 * What is known about the variables in one function body.
 *
 * Deliberately flat and forward-only: a variable's type is whatever the last thing that could
 * establish it said. The one exception is `instanceof`, because PHP guarantees it: inside
 * `if ($x instanceof C) {…}` the variable is a C, and the type it had before comes back when
 * the block ends. Otherwise a receiver whose type depends on which arm of a conditional ran is
 * a receiver this engine should be admitting it does not know, not one it should be guessing at.
 */
final class Scope
{
    /**
     * One frame per open `if` that narrowed something: each variable, the binding it had
     * before, and the narrowed type put in its place.
     *
     * @var list<list<array{string, ResolvedType|\Closure(): ?ResolvedType|null, ResolvedType}>>
     */
    private array $narrowed = [];

    /**
     * A type, or how to work it out the first time it is asked for. Most variables a body
     * assigns are never the receiver of the call being searched for, so typing each assignment
     * as it is met paid for a great deal of inference — a class's whole ancestry read for its
     * `@method` tags — that nothing used.
     *
     * @var array<string, ResolvedType|\Closure(): ?ResolvedType>
     */
    private array $variables = [];

    public function __construct(
        public readonly ?string $classFqn,
        public readonly ?string $parentFqn,
    ) {}

    public function set(string $variable, ResolvedType $type): void
    {
        $this->variables[$variable] = $type;
    }

    /**
     * Binds a variable to a type worked out only if something asks for it.
     *
     * @param  \Closure(): ?ResolvedType  $type  null when the binding establishes nothing
     */
    public function setLazy(string $variable, \Closure $type): void
    {
        $this->variables[$variable] = $type;
    }

    public function get(string $variable): ?ResolvedType
    {
        $binding = $this->variables[$variable] ?? null;

        if ($binding instanceof \Closure) {
            $binding = $binding();

            if ($binding === null) {
                unset($this->variables[$variable]);
            } else {
                $this->variables[$variable] = $binding;
            }
        }

        return $binding;
    }

    /**
     * Narrowings waiting for the node they start at, keyed by its id, each with the id of the
     * node it ends at.
     *
     * @var array<int, list<array{int, array<string, ResolvedType>}>>
     */
    private array $startsAt = [];

    /**
     * How many narrowings that did start end at each node. Only a narrowing that started is
     * ended, so one whose start was walked under another scope (a closure) cannot end another's.
     *
     * @var array<int, int>
     */
    private array $endsAt = [];

    /**
     * Narrows each variable to its class from entering `$from` until leaving `$to`.
     *
     * @param  array<string, ResolvedType>  $types
     */
    public function narrowBetween(Node $from, Node $to, array $types): void
    {
        if ($types !== []) {
            $this->startsAt[spl_object_id($from)][] = [spl_object_id($to), $types];
        }
    }

    public function entering(Node $node): void
    {
        foreach ($this->startsAt[spl_object_id($node)] ?? [] as [$to, $types]) {
            $this->beginNarrowing($types);
            $this->endsAt[$to] = ($this->endsAt[$to] ?? 0) + 1;
        }

        unset($this->startsAt[spl_object_id($node)]);
    }

    public function leaving(Node $node): void
    {
        for ($n = $this->endsAt[spl_object_id($node)] ?? 0; $n > 0; $n--) {
            $this->endNarrowing();
        }

        unset($this->endsAt[spl_object_id($node)]);
    }

    /**
     * @param  array<string, ResolvedType>  $types
     */
    private function beginNarrowing(array $types): void
    {
        $frame = [];

        foreach ($types as $variable => $type) {
            $frame[] = [$variable, $this->variables[$variable] ?? null, $type];
            $this->variables[$variable] = $type;
        }

        $this->narrowed[] = $frame;
    }

    /**
     * Gives each narrowed variable back the type it had before, unless the block assigned it
     * something else, which then stands as any other assignment does.
     */
    private function endNarrowing(): void
    {
        foreach (array_pop($this->narrowed) ?? [] as [$variable, $before, $narrowed]) {
            if (($this->variables[$variable] ?? null) !== $narrowed) {
                continue;
            }

            if ($before === null) {
                unset($this->variables[$variable]);
            } else {
                $this->variables[$variable] = $before;
            }
        }
    }

    /**
     * Takes what a closure can see of the scope it is written in: everything, for an arrow
     * function, which captures by value automatically; only the named variables, for a
     * `function () use ($a, $b)`.
     *
     * @param  ?list<string>  $only
     */
    public function inherit(self $enclosing, ?array $only = null): void
    {
        foreach ($enclosing->variables as $name => $type) {
            if ($only === null || in_array($name, $only, true)) {
                $this->variables[$name] = $type;
            }
        }
    }

    /**
     * `$this` and `self` are the same class here; `static` is deliberately treated as the
     * declaring class too. Late static binding could make it a subclass, so a reference found
     * through it is reported at the declaring class — which is where the declaration a caller
     * asked about actually lives.
     */
    public function resolveKeyword(string $name): ?ResolvedType
    {
        return match (strtolower($name)) {
            'self', 'static' => $this->classFqn === null ? null : ResolvedType::resolvedBy($this->classFqn, 'self'),
            'parent' => $this->parentFqn === null ? null : ResolvedType::resolvedBy($this->parentFqn, 'parent'),
            default => null,
        };
    }
}

<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Inference;

/**
 * What is known about the variables in one function body.
 *
 * Deliberately flat and forward-only: a variable's type is whatever the last thing that could
 * establish it said, and nothing here reasons about branches. That is a real limit, and it is
 * the right one — a receiver whose type depends on which arm of a conditional ran is a receiver
 * this engine should be admitting it does not know, not one it should be guessing at.
 */
final class Scope
{
    /** @var array<string, ResolvedType> */
    private array $variables = [];

    public function __construct(
        public readonly ?string $classFqn,
        public readonly ?string $parentFqn,
    ) {}

    public function set(string $variable, ResolvedType $type): void
    {
        $this->variables[$variable] = $type;
    }

    public function get(string $variable): ?ResolvedType
    {
        return $this->variables[$variable] ?? null;
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

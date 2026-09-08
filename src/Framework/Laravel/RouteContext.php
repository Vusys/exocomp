<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Framework\Laravel;

/**
 * What a route group contributes to the routes inside it.
 *
 * Groups nest, and a URI is only correct if every enclosing prefix is applied in order — which
 * is the whole reason `grep "'orders'" routes/` gives an answer that looks right and is not.
 */
final readonly class RouteContext
{
    /**
     * @param  list<string>  $middleware
     */
    public function __construct(
        public string $prefix = '',
        public string $name = '',
        public array $middleware = [],
        public ?string $controller = null,
        public string $namespace = '',
    ) {}

    /**
     * @param  list<string>  $middleware
     */
    public function with(string $prefix, string $name, array $middleware, ?string $controller, string $namespace = ''): self
    {
        return new self(
            $this->join($this->prefix, $prefix),
            $this->name.$name,
            [...$this->middleware, ...$middleware],
            $controller ?? $this->controller,
            $namespace === '' ? $this->namespace : trim($this->namespace.'\\'.$namespace, '\\'),
        );
    }

    /**
     * A bare controller name written inside a `'namespace' => …` group is relative to it — the
     * spelling the framework used before callable arrays, and still common in older route files.
     */
    public function qualify(string $controller): string
    {
        // A leading backslash means the name is already absolute, and the framework's own
        // prefixing checks for exactly that before joining the group namespace on. Stripping it
        // first and qualifying anyway produces a name with the root namespace written twice —
        // which resolves to nothing, and looks authoritative while doing it.
        if (str_starts_with($controller, '\\')) {
            return ltrim($controller, '\\');
        }

        return $this->namespace === '' ? $controller : $this->namespace.'\\'.$controller;
    }

    public function uri(string $path): string
    {
        $joined = $this->join($this->prefix, $path);

        return $joined === '' ? '/' : '/'.$joined;
    }

    private function join(string $left, string $right): string
    {
        $left = trim($left, '/');
        $right = trim($right, '/');

        return match (true) {
            $left === '' => $right,
            $right === '' => $left,
            default => $left.'/'.$right,
        };
    }
}

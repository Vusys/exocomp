<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Framework\Laravel;

use Vusys\Exocomp\Symbol\Confidence;

/**
 * One route: what answers a URI, and where that was said.
 */
final readonly class RouteDefinition
{
    /**
     * @param  list<string>  $middleware
     */
    public function __construct(
        public string $method,
        public string $uri,
        public ?string $controller,
        public ?string $action,
        public ?string $name,
        public array $middleware,
        public string $path,
        public int $line,
        public Confidence $confidence,
        public ?string $note = null,
    ) {}

    public function target(): string
    {
        if ($this->controller === null) {
            return $this->action ?? '?';
        }

        return $this->action === null ? $this->controller : $this->controller.'::'.$this->action;
    }
}

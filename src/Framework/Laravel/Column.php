<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Framework\Laravel;

/**
 * One column, as the migration history last left it.
 */
final class Column
{
    /**
     * @param  list<string>  $arguments  the literal arguments after the name — a length, a precision, an enum's values
     * @param  list<string>  $modifiers  `nullable`, `default(…)`, `unsigned`, and the rest of the chain
     */
    public function __construct(
        public string $name,
        public string $type,
        public array $arguments,
        public array $modifiers,
        public string $path,
        public int $line,
    ) {}

    public function render(): string
    {
        $type = $this->arguments === [] ? $this->type : sprintf('%s(%s)', $this->type, implode(', ', $this->arguments));

        return $this->modifiers === [] ? $type : $type.' '.implode(' ', $this->modifiers);
    }
}

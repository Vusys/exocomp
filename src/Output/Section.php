<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Output;

/**
 * A titled group of rows. `members` groups by declaring origin because origin is the entire
 * value-add over reading the file; other commands use a single untitled section.
 */
final readonly class Section
{
    /**
     * @param  list<Row>  $rows
     */
    public function __construct(
        public string $title,
        public array $rows,
    ) {}
}

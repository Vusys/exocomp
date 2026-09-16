<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Analysis;

use Vusys\Exocomp\Console\Command\ShowCommand;

/**
 * An anonymous class declaration, recorded but not described.
 *
 * `new class { … }` has no name, so no *name* addresses it — but its file and line do, and
 * {@see ShowCommand} accepts that form precisely because
 * otherwise a Laravel migration, which is nothing but an anonymous class, would be unreadable
 * by every command this tool has.
 *
 * It is still reported in an outline as present, at its line, with the count of what it holds:
 * a reader who saw a file's shape with several methods missing and no indication that anything
 * was withheld would be reading a silent omission.
 */
final readonly class AnonymousClass
{
    /**
     * @param  ?Declaration  $declaration  the class as a named one would be described, so that
     *                                     a member of it can be printed once a file address has
     *                                     picked it out
     */
    public function __construct(
        public int $line,
        public int $column,
        public int $memberCount,
        public ?string $extends,
        public ?Declaration $declaration = null,
    ) {}
}

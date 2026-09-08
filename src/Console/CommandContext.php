<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Console;

use Vusys\Exocomp\Output\OutputBudget;
use Vusys\Exocomp\Output\OutputFormat;
use Vusys\Exocomp\Project\Project;
use Vusys\Exocomp\Project\SymbolLocator;

/**
 * Everything a command needs that comes from the shared options rather than from its own
 * arguments.
 */
final readonly class CommandContext
{
    public function __construct(
        public Project $project,
        public SymbolLocator $locator,
        public OutputBudget $budget,
        public OutputFormat $format,
    ) {}
}

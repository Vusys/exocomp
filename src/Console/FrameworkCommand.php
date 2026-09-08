<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Console;

/**
 * The base for framework answers that need no index.
 *
 * Migrations, config files and route files are read straight off disk: they are found by
 * convention rather than by autoloading, and half of them declare no class at all. Making
 * these commands wait on an index would be a cost with nothing bought by it.
 */
abstract class FrameworkCommand extends ExocompCommand
{
    use DetectsFramework;
    use RefusesRevision;
}

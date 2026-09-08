<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Console;

/**
 * The base for framework answers that also need the corpus — a model's ancestry, a facade's
 * accessor, the class a binding resolves to.
 */
abstract class IndexedFrameworkCommand extends IndexedCommand
{
    use DetectsFramework;
}

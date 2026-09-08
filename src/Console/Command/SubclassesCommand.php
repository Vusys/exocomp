<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Console\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Vusys\Exocomp\Index\SymbolGraph;

#[AsCommand(name: 'subclasses', description: 'List the classes that extend a class, transitively by default')]
final class SubclassesCommand extends RelationCommand
{
    protected function relatives(SymbolGraph $graph, string $fqn, bool $recursive): array
    {
        return $graph->subclasses($fqn, $recursive);
    }

    protected function noun(int $count): string
    {
        return $count === 1 ? 'subclass' : 'subclasses';
    }
}

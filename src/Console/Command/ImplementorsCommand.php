<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Console\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Vusys\Exocomp\Index\SymbolGraph;
use Vusys\Exocomp\Symbol\SymbolKind;

#[AsCommand(name: 'implementors', description: 'List the classes that implement an interface, including through their parents')]
final class ImplementorsCommand extends RelationCommand
{
    protected function relatives(SymbolGraph $graph, string $fqn, bool $recursive): array
    {
        return $graph->implementors($fqn, $recursive);
    }

    protected function expects(): array
    {
        return [SymbolKind::InterfaceType, 'for the classes that extend it: exocomp subclasses %1$s'];
    }

    protected function noun(int $count): string
    {
        return $count === 1 ? 'implementor' : 'implementors';
    }
}

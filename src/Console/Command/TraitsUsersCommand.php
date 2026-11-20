<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Console\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Vusys\Exocomp\Index\SymbolGraph;
use Vusys\Exocomp\Symbol\SymbolKind;

#[AsCommand(name: 'traits-users', description: 'List the classes that use a trait, including through their parents')]
final class TraitsUsersCommand extends RelationCommand
{
    protected function relatives(SymbolGraph $graph, string $fqn, bool $recursive): array
    {
        return $graph->traitUsers($fqn, $recursive);
    }

    protected function expects(): array
    {
        return [SymbolKind::TraitType, 'for the classes that extend it: exocomp subclasses %1$s; for an interface: exocomp implementors %1$s'];
    }

    protected function noun(int $count): string
    {
        return $count === 1 ? 'user' : 'users';
    }
}

<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Inference;

use Vusys\Exocomp\Index\SymbolGraph;
use Vusys\Exocomp\Index\SymbolRecord;
use Vusys\Exocomp\Symbol\SymbolKind;

/**
 * "Does this class declare that member" — asked from several places, answered once.
 *
 * Member lookup goes through the whole hierarchy, because a property declared on a parent is
 * still the receiver's property and resolving only against a class's own declarations would
 * fail on the ordinary case of anything with a base class.
 *
 * {@see self::isComplete()} is the half that makes a *negative* answer usable. "This class does
 * not declare that" is only worth acting on when the class was read to the end; a model whose
 * ancestry leaves the corpus two steps up has an API far larger than anything readable, and
 * treating an absence there as proof would turn every framework call into a reported error.
 */
final readonly class Members
{
    public function __construct(private SymbolGraph $graph) {}

    public function declared(string $ownerFqn, string $name, SymbolKind $kind): ?SymbolRecord
    {
        foreach ($this->graph->members($ownerFqn)['members'] as $member) {
            $record = $member->record;

            if ($record->kind !== $kind) {
                continue;
            }

            // Method and class names fold case in PHP; property and constant names do not.
            $matches = $kind === SymbolKind::Method
                ? strcasecmp($record->name, $name) === 0
                : $record->name === $name;

            if ($matches) {
                return $record;
            }
        }

        return null;
    }

    /**
     * Whether the class was read to the end of its ancestry, so that an absence means something.
     */
    public function isComplete(string $ownerFqn): bool
    {
        return $this->graph->classLike($ownerFqn) instanceof SymbolRecord
            && $this->graph->members($ownerFqn)['unreachable'] === [];
    }

    /**
     * Where the ancestry ran out, when it did.
     */
    public function unreadableAncestor(string $ownerFqn): ?string
    {
        $unreachable = $this->graph->members($ownerFqn)['unreachable'];

        return $unreachable === [] ? null : $unreachable[0]->fqn;
    }
}

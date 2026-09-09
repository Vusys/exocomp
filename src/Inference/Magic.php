<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Inference;

use Vusys\Exocomp\Framework\Laravel\EloquentConventions;
use Vusys\Exocomp\Index\SymbolGraph;
use Vusys\Exocomp\Project\ClassSource;
use Vusys\Exocomp\Symbol\Identifier;
use Vusys\Exocomp\Symbol\SymbolKind;

/**
 * The ladder a member that nothing declares is put through, in order of what the evidence is
 * worth.
 *
 * Convention comes first, because it is the only rung that can name a real declaration with
 * certainty: the framework transforms the name by a total function, and either the target is
 * there or it is not. Annotations come next — plentiful, useful, and never more than a claim.
 * Then the body of the magic method itself, which is readable in exactly one shape. Then macros,
 * which are registered somewhere else entirely and can only ever say "something of this name was
 * registered over there".
 *
 * Every rung returns null rather than guessing, and running out of rungs is a real answer: it
 * means the call goes somewhere this tool cannot see, which is what the caller needs to be told.
 */
final class Magic
{
    /** @var array<string, MagicMember|null> */
    private array $memo = [];

    /**
     * @param  list<MagicSource>  $sources
     */
    public function __construct(private readonly array $sources) {}

    /**
     * The default ladder for a project.
     *
     * The Laravel rung installs itself from the class hierarchy rather than from framework
     * detection: a class that descends from Eloquent's model is one whether or not the checkout
     * looks like an application, and a convention nobody can confirm is not applied at all.
     */
    public static function forProject(SymbolGraph $graph, ClassSource $source): self
    {
        $members = new Members($graph);

        return new self([
            new EloquentConventions($source, $members),
            new AnnotatedMembers($source, $members),
            new Forwarders($source, $members),
        ]);
    }

    public function method(string $ownerFqn, string $name): ?MagicMember
    {
        return $this->resolve($ownerFqn, $name, SymbolKind::Method);
    }

    public function property(string $ownerFqn, string $name): ?MagicMember
    {
        return $this->resolve($ownerFqn, $name, SymbolKind::Property);
    }

    /**
     * Every alternative spelling of a declared member's name, across all the rungs.
     *
     * @return list<string>
     */
    public function spellings(string $ownerFqn, string $declaredName): array
    {
        $found = [];

        foreach ($this->sources as $source) {
            foreach ($source->spellings($ownerFqn, $declaredName) as $spelling) {
                $found[strtolower($spelling)] = $spelling;
            }
        }

        return array_values($found);
    }

    private function resolve(string $ownerFqn, string $name, SymbolKind $kind): ?MagicMember
    {
        $key = Identifier::fold($ownerFqn).'::'.$kind->value.':'.$name;

        // Cached including the misses. A corpus-wide reference search asks about the same
        // handful of receivers thousands of times, and every miss here costs an ancestry walk.
        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        $this->memo[$key] = null;

        foreach ($this->sources as $source) {
            $found = $kind === SymbolKind::Method
                ? $source->method($ownerFqn, $name)
                : $source->property($ownerFqn, $name);

            if ($found instanceof MagicMember) {
                return $this->memo[$key] = $found;
            }
        }

        return null;
    }
}

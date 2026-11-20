<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Inference;

use PhpParser\Node;
use Vusys\Exocomp\Analysis\Analyser;
use Vusys\Exocomp\Index\SymbolGraph;
use Vusys\Exocomp\Index\SymbolRecord;
use Vusys\Exocomp\Project\ClassSource;
use Vusys\Exocomp\Symbol\Confidence;
use Vusys\Exocomp\Symbol\Identifier;
use Vusys\Exocomp\Symbol\SymbolKind;

/**
 * What a class's `use A, B { … }` block does to the traits' methods.
 *
 * `B::hello as greet` gives the class a `greet()` that is `B::hello`, and `secret as public
 * revealed` a public `revealed()` that is the trait's `secret()`. `A::hello insteadof B` means a
 * call to `hello()` on the class runs `A::hello` and never `B::hello`. None of this is a
 * declaration in the class, so it is read from the block, here, as the language defines it.
 */
final class TraitAdaptations implements MagicSource
{
    /** @var array<string, array{aliases: array<string, string>, excluded: array<string, true>}> */
    private array $read = [];

    public function __construct(private readonly ClassSource $source, private readonly SymbolGraph $graph) {}

    public function method(string $ownerFqn, string $name): ?MagicMember
    {
        foreach ($this->source->withAncestors($ownerFqn) as $class) {
            $alias = $this->adaptationsOf($class->fqn)['aliases'][strtolower($name)] ?? null;

            if ($alias !== null) {
                return MagicMember::spelled($alias, null, Confidence::Resolved, sprintf('%s() is an alias of %s(), by `as` in %s', $name, $alias, $class->fqn));
            }
        }

        return null;
    }

    public function property(string $ownerFqn, string $name): ?MagicMember
    {
        return null;
    }

    /**
     * The aliases classes using the trait give `$declaredName`, so a search for the trait method
     * also looks for calls made under them.
     */
    public function spellings(string $ownerFqn, string $declaredName): array
    {
        if ($this->graph->classLike($ownerFqn)?->kind !== SymbolKind::TraitType) {
            return [];
        }

        $target = Identifier::fold($ownerFqn.'::'.$declaredName);
        $spellings = [];

        foreach ($this->graph->traitUsers($ownerFqn, recursive: false) as $user) {
            foreach ($this->adaptationsOf($user->fqn)['aliases'] as $alias => $of) {
                if (Identifier::fold($of) === $target) {
                    $spellings[] = $alias;
                }
            }
        }

        return $spellings;
    }

    /**
     * Whether `$host`'s `insteadof` rules mean a call to `$method` never runs `$trait`'s.
     */
    public function excludes(string $host, string $trait, string $method): bool
    {
        return isset($this->adaptationsOf($host)['excluded'][Identifier::fold($trait.'::'.$method)]);
    }

    /**
     * @return array{aliases: array<string, string>, excluded: array<string, true>}
     */
    private function adaptationsOf(string $fqn): array
    {
        $key = Identifier::fold($fqn);

        if (isset($this->read[$key])) {
            return $this->read[$key];
        }

        $found = ['aliases' => [], 'excluded' => []];
        $class = $this->source->load($fqn);

        foreach ($class?->node->stmts ?? [] as $statement) {
            if (! $statement instanceof Node\Stmt\TraitUse) {
                continue;
            }

            $traits = array_values(array_map(Analyser::resolvedName(...), $statement->traits));

            foreach ($statement->adaptations as $adaptation) {
                $method = $adaptation->method->toString();
                $trait = $adaptation->trait instanceof Node\Name ? Analyser::resolvedName($adaptation->trait) : $this->declaringTrait($traits, $method);

                if ($trait === null) {
                    continue;
                }

                if ($adaptation instanceof Node\Stmt\TraitUseAdaptation\Precedence) {
                    foreach ($adaptation->insteadof as $loser) {
                        $found['excluded'][Identifier::fold(Analyser::resolvedName($loser).'::'.$method)] = true;
                    }

                    continue;
                }

                if ($adaptation instanceof Node\Stmt\TraitUseAdaptation\Alias && $adaptation->newName instanceof Node\Identifier) {
                    $found['aliases'][strtolower($adaptation->newName->toString())] = $trait.'::'.$method;
                }
            }
        }

        return $this->read[$key] = $found;
    }

    /**
     * The trait among those used that declares `$method`, for an adaptation that does not say.
     *
     * @param  list<string>  $traits
     */
    private function declaringTrait(array $traits, string $method): ?string
    {
        foreach ($traits as $trait) {
            foreach ($this->graph->members($trait, includeInherited: false)['members'] as $member) {
                if ($member->record->kind === SymbolKind::Method && strcasecmp($member->record->name, $method) === 0) {
                    return $trait;
                }
            }

            if ($this->graph->classLike($trait) instanceof SymbolRecord) {
                continue;
            }

            if ($this->source->load($trait)?->node->getMethod($method) instanceof Node\Stmt\ClassMethod) {
                return $trait;
            }
        }

        return null;
    }
}

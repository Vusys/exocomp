<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Project;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassLike;
use Vusys\Exocomp\Analysis\Analyser;
use Vusys\Exocomp\Index\SymbolGraph;
use Vusys\Exocomp\Index\SymbolRecord;
use Vusys\Exocomp\Source\ParseFailure;
use Vusys\Exocomp\Source\SourceFile;
use Vusys\Exocomp\Symbol\Identifier;

/**
 * Loads a class's parsed body, by fully-qualified name, using the index only to find the file.
 *
 * A class that cannot be loaded comes back as null with the reason recorded, never as an
 * exception. Walking a model's ancestry runs off the end of the indexed source roots almost
 * immediately — `Model` itself lives in `vendor/` — and that is the ordinary case, not a
 * failure. What matters is that the caller can say "read as far as here, and no further"
 * rather than silently reporting a partial answer as a complete one.
 */
final class ClassSource
{
    /** @var array<string, LoadedClass|null> */
    private array $loaded = [];

    /** @var list<string> */
    private array $notes = [];

    public function __construct(
        private readonly Project $project,
        private readonly SymbolGraph $graph,
        private readonly Analyser $analyser = new Analyser,
    ) {}

    public function load(string $fqn): ?LoadedClass
    {
        return $this->loaded[Identifier::fold($fqn)] ??= $this->read($fqn);
    }

    /**
     * The class and everything it inherits from, nearest first, stopping at the first ancestor
     * that cannot be read.
     *
     * @return list<LoadedClass>
     */
    public function withAncestors(string $fqn): array
    {
        $chain = [];
        $own = $this->load($fqn);

        if (! $own instanceof LoadedClass) {
            return [];
        }

        $chain[] = $own;

        foreach (array_keys($this->graph->ancestorsOf($own->fqn)) as $ancestor) {
            $loaded = $this->load($ancestor);

            if ($loaded instanceof LoadedClass) {
                $chain[] = $loaded;
            }
        }

        return $chain;
    }

    /**
     * @return list<string>
     */
    public function notes(): array
    {
        return array_values(array_unique($this->notes));
    }

    private function read(string $fqn): ?LoadedClass
    {
        $record = $this->graph->classLike($fqn);

        if (! $record instanceof SymbolRecord) {
            return null;
        }

        $absolute = $this->project->path($record->path);

        if (! is_file($absolute)) {
            $this->notes[] = sprintf('%s is indexed at %s, which is no longer there', $record->fqn, $record->path);

            return null;
        }

        try {
            [$analysis, $statements] = $this->analyser->analyseWithAst(SourceFile::fromPath($absolute, $record->path));
        } catch (ParseFailure $failure) {
            $this->notes[] = sprintf('%s:%d could not be parsed: %s', $failure->path, $failure->startLine, $failure->getMessage());

            return null;
        }

        $node = $this->findClassLike($statements, $record->fqn);

        return $node instanceof ClassLike
            ? new LoadedClass($record->fqn, $record->path, $analysis, $node)
            : null;
    }

    /**
     * @param  array<int, Node>  $nodes
     */
    private function findClassLike(array $nodes, string $fqn): ?ClassLike
    {
        foreach ($nodes as $node) {
            if ($node instanceof ClassLike && $this->namesMatch($node, $fqn)) {
                return $node;
            }

            foreach ($node->getSubNodeNames() as $name) {
                $value = $node->{$name};

                if (! is_array($value)) {
                    continue;
                }

                $children = array_values(array_filter($value, static fn (mixed $child): bool => $child instanceof Node));
                $found = $this->findClassLike($children, $fqn);

                if ($found instanceof ClassLike) {
                    return $found;
                }
            }
        }

        return null;
    }

    private function namesMatch(ClassLike $node, string $fqn): bool
    {
        $namespaced = $node->namespacedName;

        return $namespaced instanceof Name && Identifier::fold($namespaced->toString()) === Identifier::fold($fqn);
    }
}

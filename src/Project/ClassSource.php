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
use Vusys\Exocomp\Symbol\SymbolRef;

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
        private readonly ?SymbolLocator $locator = null,
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
        [$path, $fqn] = $this->locate($fqn);

        if ($path === null) {
            return null;
        }

        $absolute = $this->project->path($path);

        if (! is_file($absolute)) {
            $this->notes[] = sprintf('%s was placed at %s, which is not there', $fqn, $path);

            return null;
        }

        try {
            [$analysis, $statements] = $this->analyser->analyseWithAst(SourceFile::fromPath($absolute, $path));
        } catch (ParseFailure $failure) {
            $this->notes[] = sprintf('%s:%d could not be parsed: %s', $failure->path, $failure->startLine, $failure->getMessage());

            return null;
        }

        $node = $this->findClassLike($statements, $fqn);

        return $node instanceof ClassLike
            ? new LoadedClass($fqn, $path, $analysis, $node)
            : null;
    }

    /**
     * The file a class lives in: the index first, then the autoload map.
     *
     * The fallback is what makes framework classes readable at all. `Model`, `Facade` and the
     * container itself live in `vendor/`, which is not indexed by default and should not have
     * to be — but Composer's autoload map places them exactly, so one file can be read without
     * paying to index sixty thousand.
     *
     * @return array{?string, string}
     */
    private function locate(string $fqn): array
    {
        $record = $this->graph->classLike($fqn);

        if ($record instanceof SymbolRecord) {
            return [$record->path, $record->fqn];
        }

        if (! $this->locator instanceof SymbolLocator) {
            return [null, $fqn];
        }

        try {
            $located = $this->locator->locateOwner(SymbolRef::parse($fqn));
        } catch (SymbolNotFound) {
            return [null, $fqn];
        }

        return [$located->analysis->file->displayPath, $located->declaration->fqn];
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

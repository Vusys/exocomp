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
    /**
     * How many parsed classes to keep. Each holds its file's text and its syntax tree, and the
     * magic-member ladder asks about the receiver types it meets — 1,077 distinct classes in one
     * corpus-wide reference search over a 950k-line application, which took the process from
     * 54MB to 511MB and over PHP's default limit. The answers are memoised where they are asked
     * for, so an eviction costs a re-parse and never a wrong answer.
     */
    private const int RETAINED = 128;

    /** @var array<string, LoadedClass|null> */
    private array $loaded = [];

    /** @var array<string, true> least recently used first */
    private array $order = [];

    /** @var array<string, list<string>> */
    private array $extends = [];

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
        $key = Identifier::fold($fqn);

        // `??=` would re-run the read every time the answer was null, and null is the common
        // answer: a walk through a framework application asks about hundreds of names that are
        // not in the corpus, and each retry is an index lookup, an autoload-map lookup and a
        // stat. "I already looked and there is nothing" is a cached result too.
        if (! array_key_exists($key, $this->loaded)) {
            $this->remember($key, $this->read($fqn));
        } elseif ($this->loaded[$key] instanceof LoadedClass) {
            $this->touch($key);
        }

        return $this->loaded[$key];
    }

    /**
     * Marks a parse as recently used, so eviction takes the least recently used rather than the
     * first loaded. With first-loaded order the classes asked about most — `Model` and its
     * dozen-and-a-half traits, which every model receiver walks — were evicted as readily as a
     * class met once, and one reference search re-parsed each of them twenty times.
     */
    private function touch(string $key): void
    {
        unset($this->order[$key]);
        $this->order[$key] = true;
    }

    /**
     * Caches the result, evicting the least recently used parse once the cap is reached.
     *
     * Misses are kept regardless: "I already looked and there is nothing" costs a key and saves
     * an index lookup, an autoload-map lookup and a stat every time it is asked again.
     */
    private function remember(string $key, ?LoadedClass $class): void
    {
        $this->loaded[$key] = $class;

        if (! $class instanceof LoadedClass) {
            return;
        }

        $this->touch($key);

        while (count($this->order) > self::RETAINED) {
            $oldest = array_key_first($this->order);
            unset($this->order[$oldest], $this->loaded[$oldest]);
        }
    }

    /**
     * The class and everything it inherits from, nearest first.
     *
     * The walk reads each class's own `extends` and `use` clauses rather than the index's edge
     * table, because the edge table stops wherever the corpus does. A model extending a
     * framework base class — the ordinary shape for anything authenticatable — has its parent
     * recorded as an edge to a class that is not indexed, and a graph walk halts there with no
     * sign that it did.
     *
     * `$stopAt` is where the walk should stop descending, and it earns its place twice over: it
     * keeps the read out of the framework's own base class, whose empty `$casts` and fourteen
     * traits are noise rather than facts about the model, and it keeps the cost bounded.
     *
     * @return list<LoadedClass>
     */
    public function withAncestors(string $fqn, ?string $stopAt = null): array
    {
        $own = $this->load($fqn);

        if (! $own instanceof LoadedClass) {
            return [];
        }

        $chain = [$own];
        $seen = [Identifier::fold($own->fqn) => true];
        $queue = [$own];

        while ($queue !== []) {
            $current = array_shift($queue);

            foreach ($this->parentsOf($current) as $parent) {
                $key = Identifier::fold($parent);

                if (isset($seen[$key]) || ($stopAt !== null && $key === Identifier::fold($stopAt))) {
                    continue;
                }

                $seen[$key] = true;
                $loaded = $this->load($parent);

                if ($loaded instanceof LoadedClass) {
                    $chain[] = $loaded;
                    $queue[] = $loaded;
                }
            }
        }

        return $chain;
    }

    /**
     * Whether a class has this ancestor, following only `extends`.
     *
     * Traits are deliberately not followed: "is this a Model" is a question about the class
     * hierarchy, and following every trait would parse a great deal of framework source to
     * answer it.
     */
    public function descendsFrom(string $fqn, string $ancestor, int $depth = 0): bool
    {
        if (Identifier::fold($fqn) === Identifier::fold($ancestor)) {
            return true;
        }

        if ($depth > 16) {
            return false;
        }

        return array_any($this->parentClassesOf($fqn), fn (string $parent): bool => $this->descendsFrom($parent, $ancestor, $depth + 1));
    }

    /**
     * A class's `extends` clause, kept after its parse is evicted. It is a few names against a
     * whole syntax tree, and "is this a model" asks it of the same framework ancestry for every
     * receiver it meets — without this, each question re-parsed whichever of `Model` and its
     * parents had been evicted since the last one.
     *
     * @return list<string>
     */
    private function parentClassesOf(string $fqn): array
    {
        $key = Identifier::fold($fqn);

        if (! array_key_exists($key, $this->extends)) {
            // The index already holds a corpus class's parents. Reading them from there spares a
            // parse of every application class a reference search asks "is this a model" of.
            $indexed = $this->graph->extendsOf($fqn);

            if ($indexed !== null) {
                return $this->extends[$key] = $indexed;
            }

            $class = $this->load($fqn);
            $this->extends[$key] = $class instanceof LoadedClass ? $this->extendsOf($class) : [];
        }

        return $this->extends[$key];
    }

    /**
     * What a class inherits from directly: its parents and the traits it uses.
     *
     * Public because a graph walk needs the same one-level step the ancestry walk takes, but
     * lazily — materialising every ancestor of a framework-descended class to find out where
     * one method is declared reads a great deal of source to answer a small question.
     *
     * @return list<string>
     */
    public function parentsOf(LoadedClass $class): array
    {
        foreach ($class->analysis->declarations as $declaration) {
            if (Identifier::fold($declaration->fqn) === Identifier::fold($class->fqn)) {
                return [...$declaration->traits, ...$declaration->extends];
            }
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function extendsOf(LoadedClass $class): array
    {
        foreach ($class->analysis->declarations as $declaration) {
            if (Identifier::fold($declaration->fqn) === Identifier::fold($class->fqn)) {
                return $declaration->extends;
            }
        }

        return [];
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
            $located = $this->locator->locateOwner(SymbolRef::parse($fqn), speculative: true);
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

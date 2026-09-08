<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Project;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Vusys\Exocomp\Analysis\Analyser;
use Vusys\Exocomp\Analysis\Declaration;
use Vusys\Exocomp\Analysis\FileAnalysis;
use Vusys\Exocomp\Source\ParseFailure;
use Vusys\Exocomp\Source\Revision;
use Vusys\Exocomp\Source\SourceFile;
use Vusys\Exocomp\Symbol\SymbolRef;

/**
 * Resolves a name to the declaration behind it, without an index.
 *
 * The order is authority-first: Composer's classmap, then PSR-4/PSR-0 arithmetic, then — only
 * if both miss — a bounded filename scan of the project's own source roots. Every candidate is
 * parsed and confirmed to actually declare the name before it is returned, so a stale classmap
 * or a coincidental filename produces a miss rather than a wrong answer.
 *
 * The scan exists because a name-to-file guess is the one place this can be beaten by
 * `find -name`, and losing to `find` on a name lookup would be losing on the exact ground the
 * tool claims.
 */
final class SymbolLocator
{
    private const int SCAN_BUDGET = 20000;

    /** @var array<string, FileAnalysis> */
    private array $analysed = [];

    /** @var list<string> */
    private array $notes = [];

    public function __construct(
        private readonly Project $project,
        private readonly AutoloadMap $map,
        private readonly bool $deepScan = false,
        private readonly ?Revision $revision = null,
        private readonly Analyser $analyser = new Analyser,
    ) {}

    public static function forProject(Project $project, bool $deepScan = false): self
    {
        return new self($project, AutoloadMap::forProject($project), $deepScan);
    }

    /**
     * Parses a buffer the caller already holds, rather than anything on disk. An agent editing
     * a file has its contents in hand; making it write them out first to ask a question about
     * them would be an absurd round trip.
     */
    public function analyseString(string $contents, string $displayPath): FileAnalysis
    {
        return $this->analyser->analyse(SourceFile::fromString($contents, $displayPath));
    }

    /**
     * Parses a file by path, memoised for the life of the command.
     */
    public function analyse(string $path): FileAnalysis
    {
        if ($this->revision instanceof Revision) {
            $relative = $this->project->displayPath(realpath($path) ?: $path);

            return $this->analysed[$this->revision->reference.':'.$relative]
                ??= $this->analyser->analyse($this->revision->source($relative));
        }

        $absolute = realpath($path);

        if ($absolute === false) {
            throw new RuntimeException("no such file: \"{$path}\"");
        }

        if (isset($this->analysed[$absolute])) {
            return $this->analysed[$absolute];
        }

        $file = SourceFile::fromPath($absolute, $this->project->displayPath($absolute));

        return $this->analysed[$absolute] = $this->analyser->analyse($file);
    }

    /**
     * Finds the class, interface, trait, enum or function a symbol address names.
     *
     * @throws SymbolNotFound
     */
    public function locateOwner(SymbolRef $ref): LocatedDeclaration
    {
        foreach ($this->map->candidates($ref->owner, $this->existenceOracle()) as $candidate) {
            $located = $this->declarationIn($candidate, $ref->owner);

            if ($located instanceof LocatedDeclaration) {
                return $located;
            }
        }

        $scanned = $this->scanForOwner($ref->owner);

        if ($scanned instanceof LocatedDeclaration) {
            return $scanned;
        }

        throw new SymbolNotFound((string) $ref, sprintf(
            'no declaration of "%s" found via the Composer autoload map or a scan of %s',
            $ref->owner,
            $this->describeRoots(),
        ));
    }

    /**
     * At a revision, "the file is there" is a question about the tree at that commit, not about
     * the working directory — a file deleted since is still resolvable, and one added since is
     * correctly not.
     *
     * @return (callable(string): bool)|null
     */
    private function existenceOracle(): ?callable
    {
        if (! $this->revision instanceof Revision) {
            return null;
        }

        $revision = $this->revision;
        $project = $this->project;

        return static fn (string $path): bool => $revision->has($project->displayPath($path));
    }

    /**
     * Files that could not be parsed while scanning. Surfaced rather than swallowed: a scan
     * that quietly skipped the file holding the answer would report "not found" when the
     * truth is "could not look".
     *
     * @return list<string>
     */
    public function notes(): array
    {
        return $this->notes;
    }

    private function declarationIn(string $path, string $fqn): ?LocatedDeclaration
    {
        try {
            $analysis = $this->analyse($path);
        } catch (ParseFailure $failure) {
            $this->notes[] = sprintf('%s:%d  not parsed — %s', $failure->path, $failure->startLine, $failure->getMessage());

            return null;
        }

        $declaration = $analysis->classLike($fqn) ?? $analysis->function($fqn);

        return $declaration instanceof Declaration ? new LocatedDeclaration($analysis, $declaration) : null;
    }

    /**
     * Last resort, in two passes over the project's own source roots.
     *
     * The first matches on filename only, which PSR-4 makes overwhelmingly likely to hit and
     * which costs no file reads. The second reads bytes and looks for the declaration keyword,
     * because PSR-4 is a convention rather than a law: several classes may share one file, and
     * a checkout's layout may be out of step with its `composer.json`. The second pass is the
     * expensive one and only runs when everything cheaper has missed.
     */
    private function scanForOwner(string $fqn): ?LocatedDeclaration
    {
        $position = strrpos($fqn, '\\');
        $shortName = $position === false ? $fqn : substr($fqn, $position + 1);

        $byFilename = $this->scan($fqn, fn (SplFileInfo $file): bool => strcasecmp($file->getFilename(), $shortName.'.php') === 0);

        if ($byFilename instanceof LocatedDeclaration) {
            return $byFilename;
        }

        if (! $this->shouldScanContents()) {
            $this->notes[] = 'a content scan of the source roots was not run — pass --scan to search inside files';

            return null;
        }

        return $this->scan($fqn, static fn (SplFileInfo $file): bool => self::declaresShortName($file->getPathname(), $shortName));
    }

    /**
     * Whether to fall through to reading every file's bytes.
     *
     * Measured on a 950k-line Laravel application: everything cheaper — PSR-4 arithmetic, the
     * classmap, and a filename match — accounts for all but roughly a quarter of one percent of
     * declarations, and that remainder is classes sharing a file with a differently-named
     * sibling. Reading tens of megabytes to find them costs well over a second, which on a
     * miss is the worst possible trade: the slowest answer is "no".
     *
     * So it is opt-in when Composer has dumped a classmap, and automatic when it has not —
     * because without one there is nothing else left to try.
     */
    private function shouldScanContents(): bool
    {
        return $this->deepScan || ! $this->map->hasClassMap();
    }

    /**
     * @param  callable(SplFileInfo): bool  $isCandidate
     */
    private function scan(string $fqn, callable $isCandidate): ?LocatedDeclaration
    {
        $budget = self::SCAN_BUDGET;

        foreach ($this->map->ownRoots() as $root) {
            if (! is_dir($root)) {
                continue;
            }

            foreach ($this->phpFilesIn($root) as $file) {
                if (--$budget < 0) {
                    $this->notes[] = sprintf('scan stopped after %d files (use --project to narrow)', self::SCAN_BUDGET);

                    return null;
                }

                if (! $isCandidate($file)) {
                    continue;
                }

                $located = $this->declarationIn($file->getPathname(), $fqn);

                if ($located instanceof LocatedDeclaration) {
                    return $located;
                }
            }
        }

        return null;
    }

    /**
     * A textual prefilter, not an answer: it decides which files are worth parsing. Whatever it
     * lets through is still confirmed against the parsed declaration, so its false positives
     * cost time and never correctness.
     */
    private static function declaresShortName(string $path, string $shortName): bool
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            return false;
        }

        return preg_match('/\b(?:class|interface|trait|enum)\s+'.preg_quote($shortName, '/').'\b/i', $contents) === 1;
    }

    /**
     * @return iterable<SplFileInfo>
     */
    private function phpFilesIn(string $root): iterable
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                yield $file;
            }
        }
    }

    private function describeRoots(): string
    {
        $roots = array_map($this->project->displayPath(...), $this->map->ownRoots());

        return $roots === [] ? 'the project' : implode(', ', $roots);
    }
}

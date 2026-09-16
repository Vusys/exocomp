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

    /**
     * Consulted only when everything else has missed: given a name nothing declares, it returns
     * the one name that is really declared, or null when there is not exactly one.
     *
     * @var (callable(string): ?string)|null
     */
    private $correction;

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
     * Teaches the locator how to correct a guessed namespace.
     *
     * Set rather than injected because the only thing that can answer it is the index, and the
     * index hangs off the context that is built *from* this locator. The dependency is real and
     * it points the other way; a setter is the honest way to say so.
     *
     * @param  (callable(string): ?string)|null  $correction
     */
    public function correctWith(?callable $correction): void
    {
        $this->correction = $correction;
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
    /**
     * @param  bool  $speculative  true when the caller is reading as far as it can rather than
     *                             asking about a name someone typed — see {@see self::notes()}
     */
    public function locateOwner(SymbolRef $ref, bool $speculative = false): LocatedDeclaration
    {
        $before = count($this->notes);
        $located = $this->locateFqn($ref->owner, $speculative);

        if ($located instanceof LocatedDeclaration) {
            return $located;
        }

        $corrected = $this->corrected($ref->owner, $speculative);

        if ($corrected instanceof LocatedDeclaration) {
            $this->withdrawScanAdvice($before);

            return $corrected;
        }

        throw new SymbolNotFound((string) $ref, sprintf(
            'no declaration of "%s" found via the Composer autoload map or a scan of %s',
            $ref->owner,
            $this->describeRoots(),
        ));
    }

    private function locateFqn(string $fqn, bool $speculative): ?LocatedDeclaration
    {
        foreach ($this->map->candidates($fqn, $this->existenceOracle()) as $candidate) {
            $located = $this->declarationIn($candidate, $fqn);

            if ($located instanceof LocatedDeclaration) {
                return $located;
            }
        }

        return $this->scanForOwner($fqn, $speculative);
    }

    /**
     * The declaration the caller meant, when the name it gave was the right class under the
     * wrong namespace.
     *
     * Guessing a namespace is by a wide margin the commonest way a name fails to resolve here:
     * an agent knows the class is called `GatewayService` and puts it under `App\Services\`
     * when it lives under `App\Services\Gateways\`. The index already holds the answer, and
     * printing it as a hint rather than acting on it charged a whole extra round trip for a
     * fact the tool had already worked out.
     *
     * It is a correction and not a search: it applies only when *exactly one* declared name
     * carries that short name, so there is nothing to choose between and no guess being made.
     * Where several do, the ambiguity is real and the caller is told about it instead.
     *
     * Never on a speculative lookup. Walking an ancestry or a magic ladder asks about hundreds
     * of names that legitimately do not exist, and silently substituting a same-named class
     * from somewhere else would fabricate a hierarchy — the confidently-wrong answer this whole
     * engine is arranged to avoid.
     */
    private function corrected(string $owner, bool $speculative): ?LocatedDeclaration
    {
        if ($speculative || $this->correction === null) {
            return null;
        }

        $real = ($this->correction)($owner);

        if ($real === null || strcasecmp($real, $owner) === 0) {
            return null;
        }

        $located = $this->locateFqn($real, speculative: true);

        if (! $located instanceof LocatedDeclaration) {
            return null;
        }

        // Loud, and phrased as a substitution. An answer about a different class than the one
        // asked for is only safe if it is impossible to read as an answer about the one asked
        // for; a reader who skims this note must still come away knowing the name was wrong.
        $this->notes[] = sprintf('nothing is declared as "%s" — answered %s instead', $owner, $real);

        return $located;
    }

    /**
     * Takes back the advice to scan, once the name has been placed after all.
     *
     * The failed first pass leaves a note telling the caller to re-run with `--scan`. That was
     * sound while the answer was "not found"; alongside an answer it sends a reader off to
     * search for a class that was never under the name they asked for. Only the notes this one
     * lookup added are withdrawn — another address in the same command may have missed for
     * real, and its advice still stands.
     */
    private function withdrawScanAdvice(int $before): void
    {
        $added = array_slice($this->notes, $before);
        $this->notes = array_slice($this->notes, 0, $before);

        foreach ($added as $note) {
            if (! str_contains($note, 'content scan of the source roots')) {
                $this->notes[] = $note;
            }
        }
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
     * Deduplicated, because the same remark reached twice is one fact and not two. A single
     * command can locate hundreds of names — a graph walk locates one per node — and every one
     * that falls back to the autoload map adds the same note. Repeated verbatim a hundred
     * times it stops being a remark and starts being the output.
     *
     * @return list<string>
     */
    public function notes(): array
    {
        return array_values(array_unique($this->notes));
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
    private function scanForOwner(string $fqn, bool $speculative = false): ?LocatedDeclaration
    {
        $position = strrpos($fqn, '\\');
        $shortName = $position === false ? $fqn : substr($fqn, $position + 1);

        $byFilename = $this->scan($fqn, fn (SplFileInfo $file): bool => strcasecmp($file->getFilename(), $shortName.'.php') === 0);

        if ($byFilename instanceof LocatedDeclaration) {
            return $byFilename;
        }

        if (! $this->shouldScanContents()) {
            // Only worth saying about a name someone asked for. A speculative read — walking an
            // ancestry, or putting a receiver through the magic ladder — asks about hundreds of
            // names that legitimately do not exist, and telling the reader their answer might be
            // incomplete because one of those missed is how a note stops being read at all.
            if (! $speculative) {
                $this->notes[] = 'a content scan of the source roots was not run — pass --scan to search inside files';
            }

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

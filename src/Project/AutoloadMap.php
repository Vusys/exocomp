<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Project;

/**
 * Composer's own view of where a class lives.
 *
 * Reading the autoload map is why this tool is written in PHP (§4.1): a fully-qualified name
 * maps to a file by rules Composer already publishes, so Phase 0 can answer `show
 * App\Services\OrderService::handle` with no index at all — no build step, no warm-up, nothing
 * to pay before the first answer.
 *
 * Both sources are used, in order of authority: `vendor/composer/*` when dependencies are
 * installed (it covers vendor packages too), and the root `composer.json` otherwise, so a
 * fresh checkout with no `vendor/` still resolves its own application classes.
 */
final class AutoloadMap
{
    /** @var array<string, string>|null FQN => absolute path; null until the map is needed */
    private ?array $classMap = null;

    /**
     * @param  array<string, list<string>>  $psr4  namespace prefix => absolute directories
     * @param  array<string, list<string>>  $psr0  namespace prefix => absolute directories
     * @param  list<string>  $ownRoots  directories declared by the project itself, for fallback scanning
     */
    private function __construct(
        private readonly string $classMapPath,
        private readonly array $psr4,
        private readonly array $psr0,
        private readonly array $ownRoots,
    ) {}

    public static function forProject(Project $project): self
    {
        $psr4 = self::includeDirectoryMap($project->path('vendor/composer/autoload_psr4.php'));
        $psr0 = self::includeDirectoryMap($project->path('vendor/composer/autoload_namespaces.php'));

        $declared = self::fromComposerJson($project);

        // The installed map wins where both exist — it reflects what `composer dump-autoload`
        // actually resolved, including package overrides — but the declared map fills in the
        // gaps when `vendor/` is absent, which is the common case in a fresh worktree.
        foreach ($declared as $prefix => $directories) {
            $psr4[$prefix] = [...($psr4[$prefix] ?? []), ...$directories];
        }

        $ownRoots = self::classmapRoots($project);

        foreach ($declared as $directories) {
            foreach ($directories as $directory) {
                $ownRoots[] = $directory;
            }
        }

        return new self(
            $project->path('vendor/composer/autoload_classmap.php'),
            $psr4,
            $psr0,
            array_values(array_unique($ownRoots)),
        );
    }

    /**
     * Directories listed under `autoload.classmap`, which is how a project declares source that
     * does not follow PSR-4 — a test suite whose classes are named freely, most commonly. They
     * are the project's own code by Composer's own reckoning, so a scan that skips them is
     * skipping code the autoloader would load.
     *
     * @return list<string>
     */
    private static function classmapRoots(Project $project): array
    {
        $roots = [];

        foreach (self::composerSection($project, 'classmap') as $entry) {
            $path = $project->path($entry);

            if (is_dir($path)) {
                $roots[] = rtrim($path, '/').'/';
            }
        }

        return $roots;
    }

    /**
     * @return list<string>
     */
    private static function composerSection(Project $project, string $key): array
    {
        $contents = @file_get_contents($project->path('composer.json'));

        if ($contents === false) {
            return [];
        }

        $decoded = json_decode($contents, true);

        if (! is_array($decoded)) {
            return [];
        }

        $entries = [];

        foreach (['autoload', 'autoload-dev'] as $sectionName) {
            $section = $decoded[$sectionName] ?? null;

            if (! is_array($section) || ! is_array($section[$key] ?? null)) {
                continue;
            }

            foreach ($section[$key] as $entry) {
                if (is_string($entry)) {
                    $entries[] = $entry;
                }
            }
        }

        return $entries;
    }

    /**
     * Every file that could declare this name, cheapest first. These are candidates, not
     * answers: the caller parses each and confirms the declaration is really there, so a stale
     * entry produces a miss rather than a confident lie.
     *
     * PSR-4 arithmetic comes before the classmap, which inverts Composer's own precedence, and
     * the reason is measured. Composer generates classmap entries for a project's own classes
     * only under `dump-autoload -o`; without it the map holds vendor classes exclusively, so
     * loading it to answer a question about application code is pure cost — and it is not a
     * small one, since the file is a single PHP array of tens of thousands of entries.
     *
     * The two can only disagree when a second file declares the same fully-qualified name,
     * which is a duplicate-class bug in the project rather than an ambiguity to arbitrate.
     * Because every candidate is verified against the parsed declaration, the answer is a file
     * that genuinely declares the name either way.
     *
     * @param  (callable(string): bool)|null  $exists  what counts as a file that is there;
     *                                                 defaults to the working tree, and a
     *                                                 revision supplies its own
     * @return list<string>
     */
    public function candidates(string $fqn, ?callable $exists = null): array
    {
        $fqn = ltrim($fqn, '\\');
        $paths = [];

        foreach ($this->psr4 as $prefix => $directories) {
            if ($prefix !== '' && ! str_starts_with($fqn, $prefix)) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($fqn, strlen($prefix))).'.php';

            foreach ($directories as $directory) {
                $paths[] = rtrim($directory, '/').'/'.$relative;
            }
        }

        foreach ($this->classMap() as $mapped => $path) {
            if (strcasecmp($mapped, $fqn) === 0) {
                $paths[] = $path;
            }
        }

        foreach ($this->psr0 as $prefix => $directories) {
            if ($prefix !== '' && ! str_starts_with($fqn, $prefix)) {
                continue;
            }

            // PSR-0 also maps underscores in the class name to directory separators.
            $relative = str_replace('\\', '/', $fqn);
            $position = strrpos($relative, '/');
            $relative = $position === false
                ? str_replace('_', '/', $relative)
                : substr($relative, 0, $position + 1).str_replace('_', '/', substr($relative, $position + 1));

            foreach ($directories as $directory) {
                $paths[] = rtrim($directory, '/').'/'.$relative.'.php';
            }
        }

        return array_values(array_unique(array_filter($paths, $exists ?? is_file(...))));
    }

    /**
     * @return list<string>
     */
    public function ownRoots(): array
    {
        return $this->ownRoots;
    }

    /**
     * The same map, told about source directories Composer does not declare.
     *
     * Composer's autoload sections are the wrong place to look for some of a project's own
     * code, because a framework may load a directory by scanning it rather than by name —
     * Laravel's migrations are the case that bites, since they declare anonymous classes that
     * no autoloader could ever resolve. Files under such a directory are still the project's
     * own source, and a search that cannot see them reports "not found" about code that is
     * plainly there.
     *
     * Kept separate from {@see self::forProject()} so that this class keeps meaning exactly one
     * thing — Composer's view — and whoever knows about frameworks does the composing.
     *
     * @param  list<string>  $directories  absolute paths; non-directories are ignored
     */
    public function withRoots(array $directories): self
    {
        $roots = $this->ownRoots;

        foreach ($directories as $directory) {
            if (is_dir($directory)) {
                // Trailing slash to match the shape Composer's own PSR-4 targets arrive in, so
                // a reported root list does not advertise where each entry came from.
                $roots[] = rtrim($directory, '/').'/';
            }
        }

        return new self($this->classMapPath, $this->psr4, $this->psr0, array_values(array_unique($roots)));
    }

    /**
     * Whether Composer has dumped a classmap at all. Its absence means dependencies are not
     * installed, which changes what the locator can lean on.
     */
    public function hasClassMap(): bool
    {
        return is_file($this->classMapPath);
    }

    /**
     * @return array<string, string>
     */
    private function classMap(): array
    {
        return $this->classMap ??= $this->includeMap($this->classMapPath);
    }

    /**
     * @return array<string, list<string>>
     */
    private static function fromComposerJson(Project $project): array
    {
        $contents = @file_get_contents($project->path('composer.json'));

        if ($contents === false) {
            return [];
        }

        $decoded = json_decode($contents, true);

        if (! is_array($decoded)) {
            return [];
        }

        $map = [];

        foreach (['autoload', 'autoload-dev'] as $sectionName) {
            $section = $decoded[$sectionName] ?? null;

            if (! is_array($section)) {
                continue;
            }

            $psr4 = $section['psr-4'] ?? null;

            if (! is_array($psr4)) {
                continue;
            }

            foreach ($psr4 as $prefix => $target) {
                if (! is_string($prefix)) {
                    continue;
                }

                $directories = is_array($target) ? $target : [$target];

                foreach ($directories as $directory) {
                    if (! is_string($directory)) {
                        continue;
                    }

                    $map[$prefix][] = $project->path($directory);
                }
            }
        }

        return $map;
    }

    /**
     * @return array<string, string>
     */
    private function includeMap(string $path): array
    {
        $map = self::includeFile($path);
        $result = [];

        foreach ($map as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * @return array<string, list<string>>
     */
    private static function includeDirectoryMap(string $path): array
    {
        $map = self::includeFile($path);
        $result = [];

        foreach ($map as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            $directories = is_array($value) ? $value : [$value];
            $result[$key] = array_values(array_filter($directories, is_string(...)));
        }

        return $result;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function includeFile(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        // Included in a static closure so the `$vendorDir`/`$baseDir` locals these generated
        // files declare cannot collide with anything here.
        $included = (static fn (): mixed => require $path)();

        return is_array($included) ? $included : [];
    }
}

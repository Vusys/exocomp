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

        return new self(
            $project->path('vendor/composer/autoload_classmap.php'),
            $psr4,
            $psr0,
            array_values(array_unique(array_merge(...array_values($declared)))),
        );
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
     * @return list<string>
     */
    public function candidates(string $fqn): array
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

        return array_values(array_unique(array_filter($paths, is_file(...))));
    }

    /**
     * @return list<string>
     */
    public function ownRoots(): array
    {
        return $this->ownRoots;
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

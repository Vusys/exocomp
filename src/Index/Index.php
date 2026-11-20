<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Index;

use PhpParser\PhpVersion;
use Vusys\Exocomp\Console\Application;
use Vusys\Exocomp\Project\AutoloadMap;
use Vusys\Exocomp\Project\Project;

/**
 * Where the cache lives, and when it is brought up to date.
 *
 * It lives outside the project on purpose. Worktrees are disposable and are created and thrown
 * away constantly; a cache inside one dies with it, and the second worktree pays the full build
 * again. A shared, content-addressed cache means the second one costs a directory walk.
 *
 * Nothing here is ever a precondition. Commands that can answer without an index do so, and the
 * ones that cannot bring it up to date themselves rather than telling the caller to run a build
 * step first — the requirement to do that is what stopped the obvious prior art being used at
 * all.
 */
final class Index
{
    private ?SymbolGraph $graph = null;

    private ?Freshness $freshnessChecker = null;

    private function __construct(
        private readonly Project $project,
        private readonly AutoloadMap $map,
        public readonly IndexStore $store,
    ) {}

    public static function open(Project $project, AutoloadMap $map): self
    {
        return new self($project, $map, new IndexStore(self::cachePath()));
    }

    public static function cacheDirectory(): string
    {
        $explicit = getenv('EXOCOMP_CACHE_DIR');

        if (is_string($explicit) && $explicit !== '') {
            return rtrim($explicit, '/');
        }

        $xdg = getenv('XDG_CACHE_HOME');

        if (is_string($xdg) && $xdg !== '') {
            return rtrim($xdg, '/').'/exocomp';
        }

        $home = getenv('HOME');

        return ($home === false || $home === '' ? sys_get_temp_dir() : rtrim($home, '/').'/.cache').'/exocomp';
    }

    public static function cachePath(): string
    {
        return self::cacheDirectory().'/'.IndexSchema::fileName(
            Application::VERSION,
            PhpVersion::getNewestSupported()->id.'-'.self::parserVersion(),
        );
    }

    public function graph(bool $validate = true, bool $includeVendor = false): SymbolGraph
    {
        return $this->graph ??= new SymbolGraph(
            $this->store,
            $this->project->root,
            $validate ? $this->freshness($includeVendor) : null,
        );
    }

    public function freshness(bool $includeVendor = false): Freshness
    {
        return $this->freshnessChecker ??= new Freshness(
            $this->store,
            $this->project,
            new Indexer($this->store),
            $this->roots($includeVendor),
        );
    }

    /**
     * @param  bool  $includeVendor  index dependencies too; off by default because nobody needs
     *                               all of them, but the demand for framework internals is real
     *                               and concentrated
     */
    public function sync(bool $includeVendor = false, bool $dropVendor = false): IndexReport
    {
        return new Indexer($this->store)->sync($this->project, $this->roots($includeVendor, $dropVendor));
    }

    /**
     * @return array{files: int, symbols: int, edges: int, blobs: int, failures: int, shared: int}
     */
    public function statistics(): array
    {
        return $this->store->statistics($this->project->root);
    }

    public function isEmpty(): bool
    {
        return $this->statistics()['files'] === 0;
    }

    /**
     * The directories a sync reads. Dependencies are among them when asked for, and also once
     * they are indexed: a sync that left them out would delete them as stale, so the next query
     * without `--vendor` undid the `index --warm --vendor` every hint recommends. Only
     * `$dropVendor` (`index --no-vendor`) takes them out again.
     *
     * @return list<string>
     */
    public function roots(bool $includeVendor, bool $dropVendor = false): array
    {
        $roots = $this->map->ownRoots();

        if (! $dropVendor && ($includeVendor || $this->store->holdsPathsUnder($this->project->root, 'vendor/'))) {
            $roots[] = $this->project->path('vendor');
        }

        return array_values(array_filter($roots, is_dir(...)));
    }

    /**
     * php-parser publishes no version constant, so the installed path's own content stands in
     * for one: what matters is only that an upgrade produces a different cache identity.
     */
    private static function parserVersion(): string
    {
        $installed = dirname(__DIR__, 2).'/vendor/composer/installed.php';

        if (! is_file($installed)) {
            return 'unknown';
        }

        $hash = hash_file('xxh128', $installed);

        return $hash === false ? 'unknown' : substr($hash, 0, 12);
    }
}

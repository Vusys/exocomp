<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Framework\Laravel;

use Vusys\Exocomp\Framework\Detection;
use Vusys\Exocomp\Framework\Profile;
use Vusys\Exocomp\Project\Project;

/**
 * Recognising a Laravel checkout, without booting one.
 *
 * Detection reads `composer.json` and looks for `artisan`, and stops there. Booting the
 * application would answer more questions and would also require a working `.env` and a
 * reachable database — which the disposable worktrees this tool is built for routinely lack.
 * A framework profile that only works in a fully provisioned checkout is a profile that is
 * unavailable exactly when someone is spelunking an unfamiliar branch.
 */
final class LaravelProfile implements Profile
{
    public const string MODEL = 'Illuminate\Database\Eloquent\Model';

    public const string FACADE = 'Illuminate\Support\Facades\Facade';

    public const string SERVICE_PROVIDER = 'Illuminate\Support\ServiceProvider';

    public function name(): string
    {
        return 'laravel';
    }

    public function detect(Project $project): ?Detection
    {
        $required = $this->composerRequirement($project);

        if ($required !== null) {
            return new Detection('Laravel', $this->installedVersion($project) ?? $this->clean($required), 'composer.json requires laravel/framework');
        }

        if (is_file($project->path('artisan')) && is_dir($project->path('app'))) {
            return new Detection('Laravel', $this->installedVersion($project), 'artisan and app/ are present');
        }

        return null;
    }

    /**
     * Where the framework keeps each kind of thing. These are conventions rather than
     * guarantees — a project may move them — so every reader that uses one also reports the
     * directory it read, and says so when the directory is not there.
     */
    public function configDirectory(Project $project): string
    {
        return $project->path('config');
    }

    public function migrationsDirectory(Project $project): string
    {
        return $project->path('database/migrations');
    }

    public function routesDirectory(Project $project): string
    {
        return $project->path('routes');
    }

    /**
     * The schema as the migration history leaves it: the squashed dump first, then everything
     * that has run since, in filename order.
     */
    public function schema(Project $project): SchemaReplay
    {
        $replay = new SchemaReplay;

        foreach (SchemaDump::dumpsIn($project->path('database/schema')) as $dump) {
            $replay->withDump($dump, $project->displayPath($dump));
        }

        return $replay->replay(
            $this->migrationsDirectory($project),
            static fn (string $absolute): string => $project->displayPath($absolute),
        );
    }

    private function composerRequirement(Project $project): ?string
    {
        $manifest = $project->path('composer.json');

        if (! is_file($manifest)) {
            return null;
        }

        $contents = file_get_contents($manifest);

        if ($contents === false) {
            return null;
        }

        $decoded = json_decode($contents, true);

        if (! is_array($decoded)) {
            return null;
        }

        foreach (['require', 'require-dev'] as $section) {
            $requirements = $decoded[$section] ?? null;

            if (! is_array($requirements)) {
                continue;
            }

            foreach (['laravel/framework', 'illuminate/support'] as $package) {
                $constraint = $requirements[$package] ?? null;

                if (is_string($constraint)) {
                    return $constraint;
                }
            }
        }

        return null;
    }

    /**
     * The version actually installed, which is what the profile's answers describe — a
     * constraint of `^11.0` says nothing about whether the reader is looking at 11.0 or 11.44.
     */
    private function installedVersion(Project $project): ?string
    {
        $lock = $project->path('composer.lock');

        if (! is_file($lock)) {
            return null;
        }

        $contents = file_get_contents($lock);

        if ($contents === false) {
            return null;
        }

        $decoded = json_decode($contents, true);
        $packages = is_array($decoded) ? ($decoded['packages'] ?? null) : null;

        if (! is_array($packages)) {
            return null;
        }

        foreach ($packages as $package) {
            if (is_array($package) && ($package['name'] ?? null) === 'laravel/framework' && is_string($package['version'] ?? null)) {
                return $this->clean($package['version']);
            }
        }

        return null;
    }

    private function clean(string $version): string
    {
        return ltrim($version, 'v^~>=< ');
    }
}

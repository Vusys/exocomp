<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Framework;

use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Framework\SourceRoots;
use Vusys\Exocomp\Project\AutoloadMap;
use Vusys\Exocomp\Project\Project;
use Vusys\Exocomp\Tests\Support\Cli;

/**
 * Source the autoload map cannot see.
 *
 * Composer's autoload sections are the authority on where a project's classes live, and on a
 * framework that loads a directory by scanning it they are incomplete. Laravel requires each
 * migration to `return new class`, so no autoloader maps them and nothing derived from the
 * autoload map can look inside — on a real application that is a four-figure count of files
 * holding the only written record of how a table came to look the way it does, and every
 * question about one of them fell back to grep.
 */
final class SourceRootsTest extends TestCase
{
    public function test_migrations_become_a_source_root_on_a_laravel_project(): void
    {
        $roots = $this->augmented(Cli::laravelRoot())->ownRoots();

        $this->assertNotEmpty(array_filter($roots, static fn (string $root): bool => str_ends_with($root, 'database/migrations/')));
    }

    public function test_routes_become_a_source_root_too(): void
    {
        $roots = $this->augmented(Cli::laravelRoot())->ownRoots();

        $this->assertNotEmpty(array_filter($roots, static fn (string $root): bool => str_ends_with($root, 'routes/')));
    }

    public function test_the_psr_four_roots_are_kept(): void
    {
        $roots = $this->augmented(Cli::laravelRoot())->ownRoots();

        $this->assertNotEmpty(array_filter($roots, static fn (string $root): bool => str_ends_with($root, 'app/')));
    }

    public function test_a_project_with_no_framework_is_left_exactly_as_it_was(): void
    {
        // Nothing here may cost a plain PHP project anything at all.
        $project = Project::at(Cli::corpusRoot());
        $map = AutoloadMap::forProject($project);

        $this->assertSame($map->ownRoots(), SourceRoots::augment($project, $map)->ownRoots());
    }

    public function test_a_classmap_directory_counts_as_the_projects_own_source(): void
    {
        // `autoload.classmap` is how a project declares source that does not follow PSR-4 — a
        // freely-named test suite, most often. Composer would load it, so a scan that skips it
        // is skipping code the autoloader knows about.
        $root = dirname(__DIR__, 2).'/Fixtures/installed';
        $roots = AutoloadMap::forProject(Project::at($root))->ownRoots();

        $this->assertNotEmpty(array_filter($roots, static fn (string $root): bool => str_ends_with($root, 'lib/')));
    }

    public function test_a_migrations_symbol_reaches_the_index(): void
    {
        $run = Cli::inLaravel(['index', '--status']);

        $this->assertStringContainsString('database/migrations/', $run->output);
    }

    private function augmented(string $root): AutoloadMap
    {
        $project = Project::at($root);

        return SourceRoots::augment($project, AutoloadMap::forProject($project));
    }
}

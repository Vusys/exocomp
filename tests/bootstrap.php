<?php

declare(strict_types=1);
use Vusys\Exocomp\Index\Index;
use Vusys\Exocomp\Project\AutoloadMap;
use Vusys\Exocomp\Project\Project;

require __DIR__.'/../vendor/autoload.php';

/**
 * The symbol cache lives outside any project, and by default that means the user's real one.
 * Tests get their own, thrown away at the end of the run, so a suite can never read a cache
 * another suite wrote or leave anything behind in `~/.cache`.
 */
$cache = sys_get_temp_dir().'/exocomp-tests-'.bin2hex(random_bytes(6));
putenv('EXOCOMP_CACHE_DIR='.$cache);

/**
 * The fixture corpora are indexed once, before anything runs.
 *
 * Not for speed. An index that has to build itself mid-test emits notes saying so — which is
 * correct behaviour and exactly what the golden files must not capture, because whether a cache
 * was cold is a property of the machine rather than of the output contract under test. Warming
 * here makes every assertion see the same state.
 */
foreach (['/Fixtures/corpus', '/Fixtures/laravel'] as $fixture) {
    $project = Project::at(__DIR__.$fixture);

    Index::open($project, AutoloadMap::forProject($project))->sync();
}

register_shutdown_function(static function () use ($cache): void {
    if (! is_dir($cache)) {
        return;
    }

    foreach (glob($cache.'/*') ?: [] as $file) {
        @unlink($file);
    }

    @rmdir($cache);
});

<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Index;

use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Console\Application;
use Vusys\Exocomp\Index\Index;
use Vusys\Exocomp\Project\AutoloadMap;
use Vusys\Exocomp\Project\Project;
use Vusys\Exocomp\Tests\Support\TemporaryProject;

/**
 * The cache's two promises: it does not lie, and a second checkout of the same content is
 * nearly free.
 *
 * The second is not a performance nicety. Worktrees here are disposable and constantly
 * recreated; a cache that had to be rebuilt for each one would be abandoned on first contact,
 * which is very likely what happened to the obvious prior art.
 */
final class IndexTest extends TestCase
{
    private TemporaryProject $project;

    protected function setUp(): void
    {
        $this->project = TemporaryProject::copyOfCorpus();
    }

    protected function tearDown(): void
    {
        $this->project->destroy();
    }

    public function test_a_first_build_records_every_parseable_file(): void
    {
        $index = $this->index();
        $report = $index->sync();

        $this->assertGreaterThan(0, $report->seen);
        $this->assertSame(0, $report->removed);
        $this->assertGreaterThan(0, $index->statistics()['files']);

        // One fixture is deliberately unparseable, and it must be recorded as such rather than
        // aborting the build.
        $this->assertSame(1, $report->knownFailures);
    }

    /**
     * Content nobody has extracted before has to be parsed; content someone has does not. The
     * fixture corpus is indexed once for the whole test run, so a fresh copy of it reuses
     * everything — which is the same property as a second worktree, observed from the inside.
     */
    public function test_only_unseen_content_is_parsed(): void
    {
        $index = $this->index();
        $index->sync();

        $this->project->write('src/TierA/Unseen'.bin2hex(random_bytes(4)).'.php', <<<'PHP'
            <?php

            namespace Fixtures\TierA;

            final class SomethingNobodyHasIndexed
            {
                public function method(): void {}
            }
            PHP);

        $report = $index->sync();

        $this->assertSame(1, $report->parsed);
    }

    public function test_a_second_run_over_unchanged_files_parses_nothing(): void
    {
        $index = $this->index();
        $index->sync();

        $again = $index->sync();

        $this->assertSame(0, $again->parsed);
        $this->assertGreaterThan(0, $again->reused);
    }

    public function test_a_file_that_will_not_parse_is_not_announced_twice(): void
    {
        $index = $this->index();
        $index->sync();

        $again = $index->sync();

        // Still counted, so a partial corpus can never read as a complete one — but not
        // re-parsed and not re-reported, since nothing about it has changed.
        $this->assertSame([], $again->failures);
        $this->assertSame(1, $again->knownFailures);
    }

    public function test_a_second_checkout_of_the_same_content_reuses_everything(): void
    {
        $this->index()->sync();

        $second = TemporaryProject::copyOfCorpus();

        try {
            $report = Index::open(
                Project::at($second->root),
                AutoloadMap::forProject(Project::at($second->root)),
            )->sync();

            // The whole point of hashing contents rather than paths: a different directory
            // holding the same bytes costs a walk, not a parse.
            $this->assertSame(0, $report->parsed);
            $this->assertGreaterThan(0, $report->reused);
        } finally {
            $second->destroy();
        }
    }

    public function test_statistics_report_what_is_shared_with_other_checkouts(): void
    {
        $this->index()->sync();

        $second = TemporaryProject::copyOfCorpus();

        try {
            $index = Index::open(Project::at($second->root), AutoloadMap::forProject(Project::at($second->root)));
            $index->sync();

            $this->assertGreaterThan(0, $index->statistics()['shared']);
        } finally {
            $second->destroy();
        }
    }

    public function test_a_deleted_file_is_dropped_from_the_index(): void
    {
        $index = $this->index();
        $index->sync();
        $before = $index->statistics()['files'];

        $this->project->delete('src/TierA/Status.php');
        $report = $index->sync();

        $this->assertSame(1, $report->removed);
        $this->assertSame($before - 1, $index->statistics()['files']);
    }

    public function test_the_cache_identity_changes_with_the_tool_version(): void
    {
        // An upgrade that changes what a signature looks like must not be able to mix new rows
        // with old ones. The cache is a different file rather than a migration.
        $this->assertStringContainsString(Application::VERSION, Index::cachePath());
    }

    private function index(): Index
    {
        $project = Project::at($this->project->root);

        return Index::open($project, AutoloadMap::forProject($project));
    }
}

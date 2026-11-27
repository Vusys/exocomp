<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Tests\Support\Cli;
use Vusys\Exocomp\Tests\Support\TemporaryProject;

/**
 * Exit `3`: not found, but not everything could be checked. The same in every command that
 * can be incomplete, so a script can tell a clean "none" from a "none, with gaps".
 */
final class IncompleteExitTest extends TestCase
{
    private TemporaryProject $project;

    protected function setUp(): void
    {
        $this->project = TemporaryProject::copyOfCorpus();
        $this->project->write('src/Gaps/Calls.php', "<?php\nnamespace Fixtures\\Gaps;\nclass Target { public function ping(): void {} }\nclass Calls { public function a(\$unknown): void { \$unknown->ping(); } }\n");
    }

    protected function tearDown(): void
    {
        $this->project->destroy();
    }

    public function test_callers_of_with_only_unresolved_candidates_is_incomplete(): void
    {
        $run = Cli::inProject($this->project, ['callers-of', 'Fixtures\Gaps\Target::ping', '--json']);

        $this->assertSame(3, $run->exitCode);
        $this->assertStringContainsString('"complete":false', $run->output);
    }

    public function test_query_with_only_undecided_matches_is_incomplete(): void
    {
        $run = Cli::inProject($this->project, ['query', '$X->ping()', '--receiver-type', 'Fixtures\Gaps\Target']);

        $this->assertSame(3, $run->exitCode);
    }

    public function test_a_clean_none_is_still_one(): void
    {
        $this->assertSame(1, Cli::inProject($this->project, ['query', '$X->neverWrittenAnywhere()'])->exitCode);
    }
}

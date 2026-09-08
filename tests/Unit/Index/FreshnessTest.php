<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Index;

use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Tests\Support\Cli;
use Vusys\Exocomp\Tests\Support\TemporaryProject;

/**
 * The index must never answer from a memory of the tree.
 *
 * A stale index is worse than no index, because it is confidently wrong — and on the write side
 * it would be confidently wrong *in the working tree*. So every one of these edits the project
 * after indexing it and then asks a question whose honest answer changed.
 *
 * The cheap reading of that requirement is to re-walk the corpus before answering, which
 * measured 83 ms on a 6,500-file project — most of a latency budget spent proving that files
 * nobody asked about have not moved. Validation is scoped to the answer instead, with one full
 * pass held in reserve for the one case scoping cannot cover: a symbol that is genuinely new,
 * which looks exactly like a symbol that does not exist.
 */
final class FreshnessTest extends TestCase
{
    private TemporaryProject $project;

    protected function setUp(): void
    {
        $this->project = TemporaryProject::copyOfCorpus();
        Cli::inProject($this->project, ['index', '--warm']);
    }

    protected function tearDown(): void
    {
        $this->project->destroy();
    }

    public function test_a_member_added_after_indexing_is_visible(): void
    {
        $this->project->write('src/TierA/Status.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Fixtures\TierA;

            enum Status
            {
                case Draft;

                case Published;

                case Archived;
            }
            PHP);

        $run = Cli::inProject($this->project, ['members', 'Fixtures\TierA\Status']);

        $this->assertStringContainsString('case Archived', $run->output);
        $this->assertStringContainsString('had changed and was re-read', $run->output);
    }

    public function test_a_member_removed_after_indexing_is_gone(): void
    {
        $this->project->write('src/TierA/Status.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Fixtures\TierA;

            enum Status
            {
                case Draft;
            }
            PHP);

        $run = Cli::inProject($this->project, ['members', 'Fixtures\TierA\Status']);

        $this->assertStringContainsString('case Draft', $run->output);
        $this->assertStringNotContainsString('case Published', $run->output);
    }

    /**
     * The case answer-scoped validation cannot catch on its own: nothing in the index points at
     * the new file, so there is no answer to validate. A miss escalates to one full pass rather
     * than reporting an absence it has not actually checked.
     */
    public function test_a_class_written_after_indexing_is_found(): void
    {
        $this->project->write('src/TierA/Fresh.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Fixtures\TierA;

            final class Fresh
            {
                public function justWritten(): void {}
            }
            PHP);

        $run = Cli::inProject($this->project, ['locate', 'Fresh']);

        $this->assertSame(0, $run->exitCode);
        $this->assertStringContainsString('Fixtures\TierA\Fresh', $run->output);
    }

    public function test_a_deleted_file_stops_being_reported(): void
    {
        $this->project->delete('src/TierA/Status.php');

        $run = Cli::inProject($this->project, ['locate', 'Fixtures\TierA\Status']);

        $this->assertSame(1, $run->exitCode);
    }

    public function test_a_file_that_stops_parsing_is_dropped_and_said_so(): void
    {
        $this->project->write('src/TierA/Status.php', "<?php\n\nnamespace Fixtures\\TierA;\n\nenum Status\n{\n    case Draft;\n");

        $run = Cli::inProject($this->project, ['locate', 'Fixtures\TierA\Status']);

        // It was in the index, its bytes changed, and it no longer parses. Continuing to serve
        // the old answer would be the exact failure the whole design is arranged against.
        $this->assertSame(1, $run->exitCode);
        $this->assertStringContainsString('no longer parses', $run->errors);
    }

    public function test_no_sync_answers_from_the_index_and_says_that_is_what_it_did(): void
    {
        $this->project->write('src/TierA/Fresh.php', "<?php\n\nnamespace Fixtures\\TierA;\n\nfinal class Fresh {}\n");

        $run = Cli::inProject($this->project, ['locate', 'Fixtures\TierA\Status', '--no-sync']);

        $this->assertSame(0, $run->exitCode);
        $this->assertStringContainsString('without checking the working tree', $run->output);
    }
}

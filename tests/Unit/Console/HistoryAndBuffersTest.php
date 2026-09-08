<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Tests\Support\Cli;
use Vusys\Exocomp\Tests\Support\Run;

/**
 * Two sources of source that are not the working tree.
 *
 * `--rev` serves a measured need — 193 commands piped `git show SHA:file` into grep — that no
 * existing PHP intelligence tool addresses at all, because they all read the working tree and
 * nothing else. It is nearly free here: the parser already takes a string, so the only new part
 * is where the string comes from.
 *
 * `--stdin` closes the other gap. An agent editing a file already holds its contents; making it
 * write them out before it can ask a question about them would be an absurd round trip.
 */
final class HistoryAndBuffersTest extends TestCase
{
    public function test_a_buffer_on_standard_input_is_analysed(): void
    {
        $run = $this->withStdin(
            file_get_contents(Cli::corpusFile('src/TierA/Status.php')) ?: '',
            ['outline', '--stdin', '--as', 'buffer.php'],
        );

        $this->assertSame(0, $run->exitCode);
        $this->assertStringContainsString('buffer.php  Fixtures\TierA\Status', $run->output);
        $this->assertStringContainsString('case Published', $run->output);
    }

    public function test_a_buffer_is_analysed_even_when_no_such_file_exists(): void
    {
        $run = $this->withStdin(
            "<?php\n\nnamespace Draft;\n\nfinal class NeverWritten\n{\n    public function only(): int\n    {\n        return 1;\n    }\n}\n",
            ['outline', '--stdin'],
        );

        $this->assertSame(0, $run->exitCode);
        $this->assertStringContainsString('(stdin)  Draft\NeverWritten', $run->output);
        $this->assertStringContainsString('public function only(): int', $run->output);
    }

    public function test_imports_reads_a_buffer_too(): void
    {
        $run = $this->withStdin(
            file_get_contents(Cli::corpusFile('src/TierB/Aliases.php')) ?: '',
            ['imports', '--stdin'],
        );

        $this->assertStringContainsString('Fixtures\TierA\Support\Repository as Store', $run->output);
    }

    public function test_an_empty_buffer_is_an_error_rather_than_an_empty_answer(): void
    {
        $run = $this->withStdin('', ['outline', '--stdin']);

        $this->assertSame(2, $run->exitCode);
        $this->assertStringContainsString('nothing arrived on standard input', $run->errors);
    }

    public function test_a_revision_is_read_from_git_rather_than_from_disk(): void
    {
        // This repository is the fixture: its own first commit is a revision that exists and
        // whose contents differ from the working tree.
        $first = trim((string) shell_exec('git -C '.escapeshellarg(dirname(__DIR__, 3)).' rev-list --max-parents=0 HEAD 2>/dev/null'));

        if ($first === '') {
            $this->markTestSkipped('not a git checkout with history');
        }

        $run = Cli::run(['show', 'Vusys\Exocomp\Output\OutputBudget::tokens', '--rev', $first]);

        $this->assertSame(0, $run->exitCode);
        $this->assertStringContainsString('OutputBudget::tokens', $run->output);
    }

    /**
     * The index describes the working tree, so a corpus-wide question cannot be asked about a
     * past revision. Accepting the flag and ignoring it would answer about today while claiming
     * to answer about then — `locate` would report a class as existing at a commit that
     * predates it, which is the confidently-wrong failure in its purest form.
     */
    public function test_an_index_backed_command_refuses_a_revision_rather_than_ignoring_it(): void
    {
        $run = Cli::run(['locate', 'OutputBudget', '--rev', 'HEAD', '--project', Cli::corpusRoot()]);

        $this->assertSame(2, $run->exitCode);
        $this->assertStringContainsString('--rev is not available for locate', $run->errors);
        $this->assertStringContainsString('show, outline and imports', $run->errors);
    }

    public function test_a_single_file_command_still_honours_a_revision(): void
    {
        $first = trim((string) shell_exec('git -C '.escapeshellarg(dirname(__DIR__, 3)).' rev-list --max-parents=0 HEAD 2>/dev/null'));

        if ($first === '') {
            $this->markTestSkipped('not a git checkout with history');
        }

        // `members --own` answers from the file rather than the index, so it can read a
        // revision like `show` does.
        $run = Cli::run(['members', 'Vusys\Exocomp\Output\OutputBudget', '--own', '--rev', $first]);

        $this->assertSame(0, $run->exitCode, $run->errors);
        $this->assertStringContainsString('own members', $run->output);
    }

    public function test_an_unknown_revision_is_an_error_that_names_it(): void
    {
        $run = Cli::run(['show', 'Vusys\Exocomp\Output\OutputBudget', '--rev', 'no-such-ref-12345']);

        $this->assertSame(2, $run->exitCode);
        $this->assertStringContainsString('"no-such-ref-12345" is not a revision', $run->errors);
    }

    /**
     * @param  list<string>  $arguments
     */
    private function withStdin(string $contents, array $arguments): Run
    {
        $handle = fopen('php://memory', 'r+');

        if ($handle === false) {
            $this->fail('could not open a memory stream');
        }

        fwrite($handle, $contents);
        rewind($handle);

        return Cli::runWithInput($handle, [...$arguments, '--project', Cli::corpusRoot()]);
    }
}

<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Tests\Support\Cli;

/**
 * Output that is not going to a terminal is plain text, whatever the environment asks for.
 *
 * Callers pipe this tool into `grep` and `head` constantly, and an escape code in that stream
 * can get it classed as binary — at which point `grep` matches nothing and says nothing. The
 * in-process tests cannot see this: they write to a memory buffer, and the colour decision is
 * made from the real stream. So these run the binary with its output piped, exactly as a
 * caller's shell would, and with `FORCE_COLOR` set, because that is the variable that leaks
 * into agents' shells and switches colour on regardless of where the output is going.
 */
final class PipedOutputTest extends TestCase
{
    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function invocations(): iterable
    {
        yield 'an answer' => [['callers-of', 'Fixtures\TierD\Notifier::send']];
        yield 'a member that is not there' => [['show', 'Fixtures\TierD\Notifier::nope']];
        yield 'a class that is not there' => [['show', 'Nope\Missing']];
        yield 'a missing argument' => [['callers-of']];
        yield 'an unknown command' => [['no-such-command']];
        yield 'help' => [['callers-of', '--help']];
        yield 'the command list' => [['list']];
    }

    /**
     * @param  list<string>  $arguments
     */
    #[DataProvider('invocations')]
    public function test_piped_output_carries_no_control_characters(array $arguments): void
    {
        [$stdout, $stderr] = $this->exocomp($arguments, ['FORCE_COLOR' => '1']);

        $this->assertPlain($stdout, 'stdout');
        $this->assertPlain($stderr, 'stderr');
    }

    public function test_ansi_is_still_honoured_when_asked_for_explicitly(): void
    {
        [$stdout] = $this->exocomp(['list', '--ansi'], []);

        $this->assertStringContainsString("\e[", $stdout);
    }

    public function test_no_color_beats_force_color(): void
    {
        [$stdout] = $this->exocomp(['list'], ['FORCE_COLOR' => '1', 'NO_COLOR' => '1']);

        $this->assertPlain($stdout, 'stdout');
    }

    private function assertPlain(string $output, string $stream): void
    {
        $this->assertTrue(mb_check_encoding($output, 'UTF-8'), "{$stream} is not valid UTF-8");
        $this->assertDoesNotMatchRegularExpression('/[\x00-\x08\x0B-\x1F\x7F]/', $output, "{$stream} carries a control character other than a newline or a tab");
    }

    /**
     * @param  list<string>  $arguments
     * @param  array<string, string>  $environment
     * @return array{string, string}
     */
    private function exocomp(array $arguments, array $environment): array
    {
        $inherited = getenv();
        unset($inherited['NO_COLOR'], $inherited['FORCE_COLOR']);

        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 3).'/bin/exocomp', ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            Cli::corpusRoot(),
            [...$inherited, ...$environment],
        );

        $this->assertIsResource($process);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return [$stdout, $stderr];
    }
}

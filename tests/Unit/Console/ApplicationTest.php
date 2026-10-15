<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Vusys\Exocomp\Console\Application;
use Vusys\Exocomp\Tests\Support\Cli;

final class ApplicationTest extends TestCase
{
    public function test_version_flag_prints_the_version_and_exits_zero(): void
    {
        $run = Cli::run(['--version']);

        $this->assertSame(0, $run->exitCode);
        $this->assertStringContainsString(Application::VERSION, $run->output);
    }

    public function test_an_unknown_option_is_an_error_and_names_what_was_probably_meant(): void
    {
        $run = static function (string ...$arguments): array {
            $application = new Application;
            $application->setAutoExit(false);
            $output = new BufferedOutput;

            return [$application->run(new ArgvInput(['exocomp', ...array_values($arguments)]), $output), $output->fetch()];
        };

        [$misspeltExit, $misspelt] = $run('members', 'App\\X', '--matchin', 'x');
        [$inventedExit, $invented] = $run('callers-of', 'App\\X::y', '--depth', '3');
        [, $elsewhere] = $run('show', 'App\\X', '--max-nodes', '3');

        // 2, not 1: this tool's 1 means "not found", and a command line that did not parse
        // found nothing only in the sense that it never looked.
        $this->assertSame(2, $misspeltExit);
        $this->assertSame(2, $inventedExit);

        $flat = static fn (string $text): string => (string) preg_replace('/\s+/', '', $text);
        $this->assertStringContainsString($flat('Did you mean --matching?'), $flat($misspelt));
        $this->assertStringContainsString($flat('callers-of answers one level'), $flat($invented));
        $this->assertStringContainsString($flat('--max-nodes is an option of: reaches, graph.'), $flat($elsewhere));
    }

    public function test_it_registers_every_phase_zero_command(): void
    {
        $names = array_keys((new Application)->all());

        $this->assertContains('show', $names);
        $this->assertContains('outline', $names);
        $this->assertContains('members', $names);
        $this->assertContains('imports', $names);
        $this->assertContains('locate', $names);
        $this->assertContains('subclasses', $names);
        $this->assertContains('implementors', $names);
        $this->assertContains('traits-users', $names);
        $this->assertContains('overrides', $names);
        $this->assertContains('references-to', $names);
        $this->assertContains('callers-of', $names);
        $this->assertContains('calls-from', $names);
        $this->assertContains('query', $names);
        $this->assertContains('check', $names);
        $this->assertContains('index', $names);
    }

    /**
     * Every command's `--help` is read by its caller at runtime, so it has to be accurate and
     * it has to exist. A command whose help does not describe its own options is a command
     * that will be used wrongly.
     */
    #[DataProvider('commands')]
    public function test_every_command_documents_the_shared_options(string $command): void
    {
        $run = Cli::run([$command, '--help']);

        $this->assertSame(0, $run->exitCode);

        foreach (['--project', '--format', '--json', '--limit', '--max-tokens', '--rev', '--stdin'] as $option) {
            $this->assertStringContainsString($option, $run->output, "{$command} --help omits {$option}");
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function commands(): iterable
    {
        yield 'show' => ['show'];
        yield 'outline' => ['outline'];
        yield 'members' => ['members'];
        yield 'imports' => ['imports'];
        yield 'locate' => ['locate'];
        yield 'subclasses' => ['subclasses'];
        yield 'implementors' => ['implementors'];
        yield 'traits-users' => ['traits-users'];
        yield 'overrides' => ['overrides'];
        yield 'references-to' => ['references-to'];
        yield 'callers-of' => ['callers-of'];
        yield 'calls-from' => ['calls-from'];
        yield 'query' => ['query'];
        yield 'check' => ['check'];
        yield 'index' => ['index'];
    }
}

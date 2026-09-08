<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
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
        yield 'index' => ['index'];
    }
}

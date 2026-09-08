<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Tests\Support\Cli;
use Vusys\Exocomp\Tests\Support\GoldenFixture;

/**
 * Whole-command snapshots.
 *
 * These answer a different question from the unit tests. Those ask "did it resolve correctly";
 * these ask "did the interface stay stable" — and for a tool whose output *is* the product,
 * unannounced drift in column alignment, header shape or the truncation notice is a real
 * regression that no semantic assertion would catch.
 */
final class GoldenTest extends TestCase
{
    #[DataProvider('catalogue')]
    public function test_command_output_matches_its_golden_file(GoldenFixture $fixture): void
    {
        $run = Cli::inCorpus($fixture->arguments);

        $this->assertSame($fixture->expected, rtrim($run->output, "\n"), $fixture->errors($run));
        $this->assertSame($fixture->expectedExitCode, $run->exitCode, $fixture->errors($run));
    }

    public function test_the_catalogue_covers_every_command(): void
    {
        $commands = array_map(
            static fn (GoldenFixture $fixture): string => $fixture->arguments[0],
            GoldenFixture::catalogue(),
        );

        foreach (['show', 'outline', 'members', 'imports', 'locate', 'subclasses', 'implementors', 'overrides'] as $command) {
            $this->assertContains($command, $commands, "no golden fixture exercises `{$command}`");
        }
    }

    /**
     * @return iterable<string, array{GoldenFixture}>
     */
    public static function catalogue(): iterable
    {
        foreach (GoldenFixture::catalogue() as $fixture) {
            yield $fixture->name => [$fixture];
        }
    }
}

<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Support;

use RuntimeException;

/**
 * One whole-command snapshot from `tests/Fixtures/Golden`.
 *
 * The format is php-parser's: three `-----`-separated sections holding a name, the command
 * line, and the expected output verbatim. Golden files answer a different question from the
 * unit tests — not "did it resolve correctly" but "did the interface stay stable" — which
 * matters here because the output contract (address first, visible truncation, the trailing
 * block) is the product.
 */
final readonly class GoldenFixture
{
    /**
     * @param  list<string>  $arguments
     */
    private function __construct(
        public string $name,
        public array $arguments,
        public int $expectedExitCode,
        public string $expected,
        public string $project = 'corpus',
    ) {}

    /**
     * @return list<self>
     */
    public static function catalogue(): array
    {
        $paths = glob(self::directory().'/*.golden');

        if ($paths === false || $paths === []) {
            throw new RuntimeException('no golden fixtures found in '.self::directory());
        }

        sort($paths);

        return array_map(self::fromFile(...), $paths);
    }

    public static function fromFile(string $path): self
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("unreadable golden fixture: {$path}");
        }

        $sections = explode("\n-----\n", $contents);

        if (count($sections) !== 3) {
            throw new RuntimeException(sprintf(
                '%s: expected 3 "-----" sections, found %d',
                basename($path),
                count($sections),
            ));
        }

        [$name, $command, $expected] = $sections;
        $exitCode = 0;
        $project = 'corpus';
        $command = trim($command);

        // Leading tags, in any order: `[exit=3]`, `[project=laravel]`.
        while (preg_match('/^\[(exit|project)=(\w+)\]\s+(.*)$/s', $command, $matches) === 1) {
            if ($matches[1] === 'exit') {
                $exitCode = (int) $matches[2];
            } else {
                $project = $matches[2];
            }

            $command = $matches[3];
        }

        if (! in_array($project, ['corpus', 'laravel'], true)) {
            throw new RuntimeException(sprintf('%s: unknown project "%s"', basename($path), $project));
        }

        // Split on whitespace, with single quotes for an argument that holds a space, such as
        // `'route:POST /orders'`. The argument "0" survives: `--arg-is-not-literal 0` needs it.
        preg_match_all("/'([^']*)'|(\S+)/", $command, $parts, PREG_SET_ORDER);

        return new self(
            name: trim($name),
            arguments: array_map(static fn (array $part): string => ($part[2] ?? '') !== '' ? $part[2] : ($part[1] ?? ''), $parts),
            expectedExitCode: $exitCode,
            expected: rtrim($expected, "\n"),
            project: $project,
        );
    }

    /**
     * The failure message for a mismatch: whatever the command wrote to stderr, so a broken
     * snapshot says *why* rather than only that two blocks of text differ.
     */
    public function errors(Run $run): string
    {
        return $run->errors === '' ? $this->name : $this->name.' — stderr: '.trim($run->errors);
    }

    /**
     * The command's output with the project's absolute root written as `<project>`: a next-page
     * command repeats `--project`, and the checkout's location is no part of the contract.
     */
    public function run(): Run
    {
        // Whether the route table is older than the route files is a fact about the checkout's
        // file times, not the output contract: a fresh clone writes them in any order.
        if ($this->project === 'laravel') {
            touch(Cli::laravelRoot().'/exocomp-routes.json');
        }

        $run = $this->project === 'laravel' ? Cli::inLaravel($this->arguments) : Cli::inCorpus($this->arguments);
        $root = $this->project === 'laravel' ? Cli::laravelRoot() : Cli::corpusRoot();

        return new Run($run->exitCode, str_replace([(string) realpath($root), $root], '<project>', $run->output), $run->errors);
    }

    public static function directory(): string
    {
        return __DIR__.'/../Fixtures/Golden';
    }
}

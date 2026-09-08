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

        if (preg_match('/^\[exit=(\d+)\]\s+(.*)$/', trim($command), $matches) === 1) {
            $exitCode = (int) $matches[1];
            $command = $matches[2];
        }

        return new self(
            name: trim($name),
            arguments: array_values(array_filter(preg_split('/\s+/', trim($command)) ?: [])),
            expectedExitCode: $exitCode,
            expected: rtrim($expected, "\n"),
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

    public static function directory(): string
    {
        return dirname(__DIR__).'/Fixtures/Golden';
    }
}

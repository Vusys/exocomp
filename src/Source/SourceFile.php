<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Source;

use RuntimeException;

/**
 * A file's bytes, plus the offset↔line arithmetic every command needs.
 *
 * Offsets are byte offsets into the raw contents, which is what the parser reports and what
 * exact-span extraction requires. Lines and columns are 1-based, because that is what
 * `file:line:col` means everywhere else and the whole output contract leans on that address
 * being clickable and identical to grep's convention.
 */
final class SourceFile
{
    /** @var list<int> byte offset at which each 1-based line starts, index 0 unused */
    private array $lineOffsets;

    private function __construct(
        public readonly string $path,
        public readonly string $displayPath,
        public readonly string $contents,
    ) {
        $this->lineOffsets = $this->indexLines($contents);
    }

    public static function fromPath(string $path, ?string $displayPath = null): self
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("could not read \"{$path}\"");
        }

        return new self($path, $displayPath ?? $path, $contents);
    }

    public static function fromString(string $contents, string $displayPath): self
    {
        return new self($displayPath, $displayPath, $contents);
    }

    /**
     * The raw bytes between two inclusive byte offsets — verbatim, no re-printing. This is how
     * `show` guarantees that what it prints is what is in the file.
     */
    public function slice(int $startOffset, int $endOffset): string
    {
        return substr($this->contents, $startOffset, $endOffset - $startOffset + 1);
    }

    /**
     * The 1-based column of a byte offset, counting bytes rather than characters — consistent
     * with the parser's own positions and with what `cut -c` would do downstream.
     */
    public function columnAt(int $offset): int
    {
        $line = $this->lineAt($offset);

        return $offset - $this->lineOffsets[$line] + 1;
    }

    /**
     * The 1-based line containing a byte offset.
     */
    public function lineAt(int $offset): int
    {
        $low = 1;
        $high = count($this->lineOffsets) - 1;

        while ($low < $high) {
            $middle = intdiv($low + $high + 1, 2);

            if ($this->lineOffsets[$middle] <= $offset) {
                $low = $middle;
            } else {
                $high = $middle - 1;
            }
        }

        return $low;
    }

    public function lineCount(): int
    {
        return count($this->lineOffsets) - 1;
    }

    /**
     * Line `$from` through `$to` inclusive, without a trailing newline.
     */
    public function lines(int $from, int $to): string
    {
        $from = max(1, $from);
        $to = min($this->lineCount(), $to);

        if ($from > $to) {
            return '';
        }

        $start = $this->lineOffsets[$from];
        $end = $to === $this->lineCount()
            ? strlen($this->contents)
            : $this->lineOffsets[$to + 1];

        return rtrim(substr($this->contents, $start, $end - $start), "\r\n");
    }

    /**
     * @return list<int>
     */
    private function indexLines(string $contents): array
    {
        // Index 0 is a placeholder so that line numbers index directly; line 1 starts at 0.
        $offsets = [0, 0];
        $length = strlen($contents);

        for ($i = 0; $i < $length; $i++) {
            if ($contents[$i] === "\n") {
                $offsets[] = $i + 1;
            }
        }

        return $offsets;
    }
}

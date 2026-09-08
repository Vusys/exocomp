<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Output;

/**
 * The default rendering: dense, address-first, shell-parseable.
 *
 * Text rather than JSON because it was measured: JSONL costs 182% more characters for the same
 * records, and code wrapped in JSON also has every newline escaped, which is harder to read as
 * well as longer. On a tool whose justification is answering the same question in fewer tokens
 * than grep, defaulting to the format that nearly triples the output would be self-defeating.
 */
final class TextRenderer implements Renderer
{
    private const int LINE_COLUMN_WIDTH = 5;

    public function render(Result $result): string
    {
        $lines = [];

        if ($result->header !== '') {
            $lines[] = $result->header;
        }

        if ($result->body !== null) {
            $lines[] = $result->body;
        }

        foreach ($result->sections as $section) {
            if ($section->title !== '') {
                $lines[] = '';
                $lines[] = sprintf('%s (%d)', $section->title, count($section->rows));
            }

            // Records spanning several files carry `path:line:col` in the text itself, the way
            // grep does; a line-number gutter would be a column of blanks in front of them.
            $gutter = $this->wantsGutter($section->rows);

            foreach ($section->rows as $row) {
                $lines[] = $gutter ? $this->renderRow($row) : $row->text;
            }
        }

        if ($result->elided > 0) {
            $lines[] = sprintf('… %d more (use --limit)', $result->elided);
        }

        if ($result->unresolved !== []) {
            $lines = [...$lines, '', ...$this->renderUnresolved($result)];
        }

        foreach ($result->notes as $note) {
            $lines[] = 'note: '.$note;
        }

        return implode("\n", $lines);
    }

    /**
     * @param  list<Row>  $rows
     */
    private function wantsGutter(array $rows): bool
    {
        foreach ($rows as $row) {
            if ($row->line === null || $row->carriesAddress) {
                return false;
            }
        }

        return $rows !== [];
    }

    private function renderRow(Row $row): string
    {
        $line = $row->line === null
            ? str_repeat(' ', self::LINE_COLUMN_WIDTH)
            : str_pad((string) $row->line, self::LINE_COLUMN_WIDTH, ' ', STR_PAD_LEFT);

        return $line.'  '.$row->text;
    }

    /**
     * The trailing block for everything the command declined to answer, with the reason that
     * would fix it. This costs nothing when everything resolves, is impossible to miss when it
     * does not, and it is the same shape the write side will need: "here is what I refuse to
     * touch, you decide".
     *
     * @return list<string>
     */
    private function renderUnresolved(Result $result): array
    {
        $title = $result->unresolvedTitle === '' ? 'unresolved' : $result->unresolvedTitle;
        $lines = [sprintf('── %d %s ──', count($result->unresolved), $title)];

        $width = 0;

        foreach ($result->unresolved as $row) {
            $width = max($width, strlen($row->text));
        }

        foreach ($result->unresolved as $row) {
            $lines[] = $row->note === null
                ? $row->text
                : str_pad($row->text, $width).'   '.$row->note;
        }

        return $lines;
    }
}

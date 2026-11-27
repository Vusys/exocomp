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

        // What could not be resolved, and the notes, lead a listing: they are what says how far
        // to trust it, and a page of records, or a reader who stops early, must not lose them.
        $summaryFirst = $result->body === null && $result->sections !== [];

        if ($summaryFirst) {
            $lines = [...$lines, ...$this->summary($result)];
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
            $lines[] = sprintf('… %d more (use %s)', $result->elided, $result->cutByTokens ? '--max-tokens' : '--limit');
        }

        if ($result->window instanceof PageWindow) {
            $lines[] = '── '.$result->window->describe().' ──';
        }

        if (! $summaryFirst) {
            $lines = [...$lines, ...$this->summary($result)];
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private function summary(Result $result): array
    {
        $lines = [];

        if ($result->unresolvedGroups !== []) {
            $lines = ['', ...$this->renderGroups($result)];
        } elseif ($result->unresolved !== []) {
            $lines = ['', ...$this->renderUnresolved($result)];
        }

        foreach ($result->notes as $note) {
            $lines[] = 'note: '.$note;
        }

        return $lines;
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
     * The trailing block, summarised: one line per reason with its count, a few examples under
     * each, and how to get the rest. The count leads so the size of each gap reads at a glance.
     *
     * @return list<string>
     */
    private function renderGroups(Result $result): array
    {
        $title = $result->unresolvedTitle === '' ? 'unresolved' : $result->unresolvedTitle;
        $total = array_sum(array_map(static fn (UnresolvedGroup $group): int => $group->count, $result->unresolvedGroups));
        $width = strlen((string) $total);
        $lines = [sprintf('── %d %s, by reason (%d shown of each; --all-unresolved lists every one) ──', $total, $title, UnresolvedGroup::EXAMPLES)];

        foreach ($result->unresolvedGroups as $group) {
            $lines[] = str_pad((string) $group->count, $width, ' ', STR_PAD_LEFT).'  '.$group->reason;

            foreach ($group->examples as $row) {
                $lines[] = str_repeat(' ', $width + 2).$row->text;
            }

            if ($group->count > count($group->examples)) {
                $lines[] = str_repeat(' ', $width + 2).sprintf('… %d more', $group->count - count($group->examples));
            }
        }

        return $lines;
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

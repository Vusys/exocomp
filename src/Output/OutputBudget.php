<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Output;

use Vusys\Exocomp\Console\ExocompCommand;

/**
 * The caller's ceiling on how much output it is willing to pay for.
 *
 * Both caps exist because context is finite and every token of output is charged for. Both are
 * *visible* when they bite — `… N more` in text, `{"elided": N}` in JSON — because the one
 * failure worse than an expensive answer is a truncated one that reads as complete. That is
 * how an audit gets wrongly signed off.
 *
 * The token ceiling applies by default whenever output is not going to a terminal, and the
 * reason is measured rather than theoretical. Across 236 real invocations, 234 were piped into
 * `head -N` and 7 used these flags — and a third of those pipes cut the answer, silently,
 * destroying the one guarantee this class exists to make. Telling callers not to do that has
 * been tried three times. A cap that is on by default cannot be forgotten, and it is set high
 * enough (see {@see ExocompCommand::DEFAULT_MAX_TOKENS}) that it
 * bounds a runaway answer without touching an ordinary one.
 */
final readonly class OutputBudget
{
    private const int CHARACTERS_PER_TOKEN = 4;

    /**
     * @param  bool  $implicit  whether `$maxTokens` is a floor the tool applied rather than a
     *                          ceiling the caller asked for — it changes only what a cut says
     *                          about itself, never where the cut falls
     */
    public function __construct(
        public int $limit = 0,
        public int $maxTokens = 0,
        public bool $implicit = false,
    ) {}

    /**
     * Caps a list of records, whatever they are — the budget is about how many things come
     * back, not about what a thing is.
     *
     * @template TRecord
     *
     * @param  list<TRecord>  $rows
     * @return array{list<TRecord>, int} the kept records and the number dropped
     */
    public function apply(array $rows): array
    {
        if ($this->limit <= 0 || count($rows) <= $this->limit) {
            return [$rows, 0];
        }

        return [array_slice($rows, 0, $this->limit), count($rows) - $this->limit];
    }

    /**
     * Renders an answer within the token ceiling, cutting listed records rather than lines.
     *
     * Cutting the rendered text kept the first lines, so the unresolved block and the notes,
     * which come last, went first; and in `--json` the cut was a line of plain text in a stream
     * of JSON. Here the records are cut, the unresolved block is summarised by reason, and the
     * renderer says how many went, as a record in JSON. Only an answer whose header and summary
     * alone are over the ceiling falls back to a line cut.
     *
     * @param  callable(Result): string  $render
     */
    public function renderWithin(Result $result, callable $render, bool $json, int $page = 1, string $command = 'exocomp'): string
    {
        $rendered = $render($result);

        if ($this->maxTokens <= 0 || (self::tokens($rendered) <= $this->maxTokens && $page <= 1)) {
            return $rendered;
        }

        // Pages are laid out from the start each time, each as many records as fit beside the
        // summary, so page N is the same whichever pages were asked for before it.
        $total = count($result->rows());
        $start = 0;
        $best = null;

        for ($current = 1; $current <= max($page, 1); $current++) {
            $next = sprintf('%s --page %d', $command, $current + 1);
            [$count, $best] = $this->largestFit($result, $render, $start, $next);

            if ($current === $page || $start + $count >= $total) {
                if ($current !== $page) {
                    return $render($result->cutTo(0, summarise: true, skip: $total)->withNote(sprintf('there is no page %d; the answer ends on page %d', $page, $current)));
                }

                break;
            }

            $start += $count;
        }

        return $best ?? $this->capRendered($render($result->cutTo(0, summarise: true)), $json);
    }

    /**
     * How many records from `$start` fit on one page beside the summary, found by halving, and
     * the page they make. At least one, so a single record longer than a page still advances.
     *
     * @param  callable(Result): string  $render
     * @return array{int, ?string}
     */
    private function largestFit(Result $result, callable $render, int $start, string $next): array
    {
        $low = 1;
        $high = max(count($result->rows()) - $start, 0);
        // One record however long, so a page always moves the reader on: over the ceiling by a
        // record is better than a page of nothing.
        $best = [$high === 0 ? 0 : 1, $high === 0 ? null : $render($result->cutTo(1, summarise: true, skip: $start, next: $next))];

        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);
            $attempt = $render($result->cutTo($middle, summarise: true, skip: $start, next: $next));

            if (self::tokens($attempt) <= $this->maxTokens) {
                $best = [$middle, $attempt];
                $low = $middle + 1;
            } else {
                $high = $middle - 1;
            }
        }

        return $best;
    }

    /**
     * Cuts rendered output to the token ceiling on a line boundary, saying how many lines went.
     * Applied after rendering rather than before, because the cost being budgeted is the cost
     * of the text itself.
     */
    public function capRendered(string $rendered, bool $json = false): string
    {
        if ($this->maxTokens <= 0 || self::tokens($rendered) <= $this->maxTokens) {
            return $rendered;
        }

        $budget = $this->maxTokens * self::CHARACTERS_PER_TOKEN;
        $lines = explode("\n", $rendered);
        $kept = [];
        $used = 0;

        foreach ($lines as $line) {
            $used += strlen($line) + 1;

            if ($used > $budget) {
                break;
            }

            $kept[] = $line;
        }

        $dropped = count($lines) - count($kept);

        if ($dropped === 0) {
            return $rendered;
        }

        $kept[] = match (true) {
            $json => (string) json_encode(['elided' => $dropped, 'unit' => 'lines', 'reason' => 'max-tokens']),
            $this->implicit => sprintf('… %d more lines, cut at the default ceiling of %d tokens because output is not a terminal (--max-tokens N to raise it, 0 to remove it)', $dropped, $this->maxTokens),
            default => sprintf('… %d more lines (use --max-tokens)', $dropped),
        };

        return implode("\n", $kept);
    }

    /**
     * A deliberately crude estimate — four characters to a token. Precision would need a
     * tokeniser for a model this tool does not know it is talking to; the point of the cap is
     * to bound a runaway answer, not to bill for it.
     */
    public static function tokens(string $text): int
    {
        return (int) ceil(strlen($text) / self::CHARACTERS_PER_TOKEN);
    }
}

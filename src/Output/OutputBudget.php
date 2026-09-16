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
     * Cuts rendered output to the token ceiling on a line boundary, saying how many lines went.
     * Applied after rendering rather than before, because the cost being budgeted is the cost
     * of the text itself.
     */
    public function capRendered(string $rendered): string
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

        $kept[] = $this->implicit
            ? sprintf('… %d more lines, cut at the default ceiling of %d tokens because output is not a terminal (--max-tokens N to raise it, 0 to remove it)', $dropped, $this->maxTokens)
            : sprintf('… %d more lines (use --max-tokens)', $dropped);

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

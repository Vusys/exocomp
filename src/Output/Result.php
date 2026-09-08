<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Output;

/**
 * A whole command's answer, before it is rendered.
 *
 * Commands build one of these and hand it to a renderer, so the output contract — address
 * first, visible truncation, the trailing unresolved block, identical structure piped or not —
 * lives in one place rather than being re-implemented, and re-broken, per command.
 */
final readonly class Result
{
    /**
     * @param  list<Section>  $sections
     * @param  list<Row>  $unresolved  things deliberately not answered, each with a reason
     * @param  list<string>  $notes  operational remarks (an unparseable file, a stopped scan)
     * @param  array<string, string|int|bool|null>|null  $object  the `--json` payload for singleton commands
     */
    public function __construct(
        public string $header = '',
        public ?string $body = null,
        public array $sections = [],
        public string $unresolvedTitle = '',
        public array $unresolved = [],
        public int $elided = 0,
        public array $notes = [],
        public ?array $object = null,
    ) {}

    /**
     * The same answer, with no more records in it than the caller asked for.
     *
     * Applied centrally rather than per command, because `--limit` is part of the output
     * contract and a command that quietly ignores it is a command whose answers cannot be
     * trusted to be complete. Commands that cap their own records still may — capping twice is
     * a no-op — but nothing depends on their remembering to.
     *
     * The trailing block is deliberately not capped. It is the list of things the tool declined
     * to answer, it is normally short, and dropping entries from it would silently remove the
     * part of the output whose whole purpose is to say what was left out. `--max-tokens` still
     * bounds it, and says so when it bites.
     */
    public function capped(OutputBudget $budget): self
    {
        $limit = $budget->limit;

        if ($limit <= 0 || count($this->rows()) <= $limit) {
            return $this;
        }

        $sections = [];
        $remaining = $limit;
        $dropped = 0;

        foreach ($this->sections as $section) {
            $kept = array_slice($section->rows, 0, max($remaining, 0));
            $dropped += count($section->rows) - count($kept);
            $remaining -= count($kept);

            if ($kept !== []) {
                $sections[] = new Section($section->title, $kept);
            }
        }

        return new self(
            header: $this->header,
            body: $this->body,
            sections: $sections,
            unresolvedTitle: $this->unresolvedTitle,
            unresolved: $this->unresolved,
            elided: $this->elided + $dropped,
            notes: $this->notes,
            object: $this->object,
        );
    }

    /**
     * @return list<Row>
     */
    public function rows(): array
    {
        $rows = [];

        foreach ($this->sections as $section) {
            foreach ($section->rows as $row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Deduplicated file paths, in first-seen order — the `--format=paths` payload, shaped for
     * `xargs`.
     *
     * @return list<string>
     */
    public function paths(): array
    {
        $paths = [];

        foreach ([...$this->rows(), ...$this->unresolved] as $row) {
            $paths[$row->file] = true;
        }

        if ($paths === [] && is_string($this->object['file'] ?? null)) {
            $paths[$this->object['file']] = true;
        }

        return array_keys($paths);
    }
}

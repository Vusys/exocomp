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
     * @param  bool  $found  whether the question had an answer — the exit code, and for a
     *                       negative one the body still carries why, because "no" and "no, and
     *                       here is what I could not look at" are different claims
     * @param  list<array<string, string|int|bool|null>>  $objects  the `--json` payload for an
     *                                                              answer covering several addresses, one record per line. Separate
     *                                                              from `$object` so that asking for one address still yields a single
     *                                                              object, rather than a consumer's shape changing under it the day a
     *                                                              command learns to take two.
     * @param  list<UnresolvedGroup>  $unresolvedGroups  the trailing block summarised by reason,
     *                                                   in place of `$unresolved`, when there was
     *                                                   too much of it to list
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
        public bool $found = true,
        public array $objects = [],
        public array $unresolvedGroups = [],
        public bool $cutByTokens = false,
        public bool $complete = true,
    ) {}

    /**
     * This answer with only the first `$keep` listed records, the rest counted as elided, and
     * the trailing unresolved block summarised by reason when `$summarise` asks. What was not
     * resolved is the part a reader most needs to see, so a token cut takes listed records
     * first and keeps the summary.
     */
    public function cutTo(int $keep, bool $summarise): self
    {
        $sections = [];
        $remaining = $keep;
        $dropped = 0;

        foreach ($this->sections as $section) {
            $kept = array_slice($section->rows, 0, max($remaining, 0));
            $dropped += count($section->rows) - count($kept);
            $remaining -= count($kept);

            if ($kept !== []) {
                $sections[] = new Section($section->title, $kept);
            }
        }

        $summarised = $summarise && $this->unresolved !== [];

        return new self(
            header: $this->header,
            body: $this->body,
            sections: $sections,
            unresolvedTitle: $this->unresolvedTitle,
            unresolved: $summarised ? [] : $this->unresolved,
            elided: $this->elided + $dropped,
            notes: $this->notes,
            object: $this->object,
            found: $this->found,
            objects: $this->objects,
            unresolvedGroups: $summarised ? UnresolvedGroup::summarise($this->unresolved) : $this->unresolvedGroups,
            cutByTokens: $dropped > 0 || $summarised || $this->cutByTokens,
            complete: $this->complete,
        );
    }

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
            found: $this->found,
            objects: $this->objects,
            unresolvedGroups: $this->unresolvedGroups,
            cutByTokens: $this->cutByTokens,
            complete: $this->complete,
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

        $examples = array_merge([], ...array_map(static fn (UnresolvedGroup $group): array => $group->examples, $this->unresolvedGroups));

        foreach ([...$this->rows(), ...$this->unresolved, ...$examples] as $row) {
            $paths[$row->file] = true;
        }

        if ($paths === [] && is_string($this->object['file'] ?? null)) {
            $paths[$this->object['file']] = true;
        }

        // A multi-address answer has no rows either, so `--format=paths` would otherwise come
        // back empty for exactly the call that names the most files.
        foreach ($this->objects as $object) {
            if (is_string($object['file'] ?? null)) {
                $paths[$object['file']] = true;
            }
        }

        // A row that could not be placed carries no file, and this format is shaped for
        // `xargs`: an empty line in it is an empty argument, not a path.
        unset($paths['']);

        return array_keys($paths);
    }
}

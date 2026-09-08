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

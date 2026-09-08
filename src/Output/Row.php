<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Output;

use Vusys\Exocomp\Symbol\Confidence;

/**
 * One record of output.
 *
 * Field names and order are shared across every command on purpose (`file`, `line`, `col`,
 * `fqn`, `kind`, `confidence`), so a caller learns one shape rather than one per command.
 */
final readonly class Row
{
    /**
     * @param  array<string, string|int|bool|null>  $extra  command-specific fields, emitted after the shared ones in `--json`
     */
    public function __construct(
        public string $file,
        public ?int $line,
        public ?int $column,
        public ?string $fqn,
        public ?string $kind,
        public string $text,
        public Confidence $confidence = Confidence::Resolved,
        public ?string $note = null,
        public array $extra = [],
    ) {}

    /**
     * @return array<string, string|int|bool|null>
     */
    public function toArray(): array
    {
        return [
            'file' => $this->file,
            'line' => $this->line,
            'col' => $this->column,
            'fqn' => $this->fqn,
            'kind' => $this->kind,
            'confidence' => $this->confidence->value,
            'text' => $this->text,
            ...($this->note === null ? [] : ['note' => $this->note]),
            ...$this->extra,
        ];
    }
}

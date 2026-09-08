<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Output;

/**
 * JSONL for record sets, a single object for singleton commands.
 *
 * This is the format for `jq` pipelines, CI consumers and structural joins — the
 * `firstOrCreate`-versus-unique-index kind of audit that needs to join code facts against
 * something outside the code. It is opt-in because it costs substantially more tokens than the
 * text form for the same information.
 */
final class JsonRenderer implements Renderer
{
    private const int FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    public function render(Result $result): string
    {
        if ($result->object !== null) {
            return $this->encode($result->object);
        }

        $lines = [];

        foreach ($result->sections as $section) {
            foreach ($section->rows as $row) {
                $record = $row->toArray();

                if ($section->title !== '') {
                    $record['section'] = $section->title;
                }

                $lines[] = $this->encode($record);
            }
        }

        foreach ($result->unresolved as $row) {
            $lines[] = $this->encode($row->toArray() + ['unresolved' => true]);
        }

        foreach ($result->notes as $note) {
            $lines[] = $this->encode(['note' => $note]);
        }

        // Truncation is a terminal record rather than a silent stop: a consumer that reads to
        // end-of-stream must be able to tell a complete answer from a capped one.
        if ($result->elided > 0) {
            $lines[] = $this->encode(['elided' => $result->elided]);
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, string|int|bool|null>  $record
     */
    private function encode(array $record): string
    {
        return json_encode($record, self::FLAGS) ?: '{}';
    }
}

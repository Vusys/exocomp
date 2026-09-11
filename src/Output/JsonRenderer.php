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

        // The verdict leads, so a consumer reading the stream knows what the records are
        // evidence for before it reads them.
        if (! $result->found) {
            $lines[] = $this->encode(['found' => false, 'note' => $result->header]);
        }

        foreach ($result->objects as $object) {
            $lines[] = $this->encode($object);
        }

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
     * Encodes one record, and never gives up quietly.
     *
     * `json_encode()` returns `false` on bytes that are not valid UTF-8, and PHP source really
     * does contain them — a string literal in another encoding is legal PHP. Falling back to
     * `{}` would put an empty object into the stream, indistinguishable from a record that
     * genuinely had nothing in it, and a consumer reading to end-of-stream would never know
     * something had been dropped. So the undecodable bytes are replaced and the substitution
     * is declared in the record itself.
     *
     * @param  array<string, string|int|bool|null>  $record
     */
    private function encode(array $record): string
    {
        $encoded = json_encode($record, self::FLAGS);

        if ($encoded !== false) {
            return $encoded;
        }

        $record['encoding'] = 'invalid UTF-8 in the source was replaced to make this record encodable';
        $substituted = json_encode($record, self::FLAGS | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($substituted !== false) {
            return $substituted;
        }

        // Nothing else survived. Keep the address, which is the part a caller can still act on.
        return (string) json_encode([
            'file' => $record['file'] ?? null,
            'line' => $record['line'] ?? null,
            'col' => $record['col'] ?? null,
            'error' => 'this record could not be encoded as JSON',
        ], self::FLAGS);
    }
}

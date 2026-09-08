<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Query;

use PhpParser\Node;
use Vusys\Exocomp\Source\SourceFile;

/**
 * What a match bound, and the source each binding came from.
 *
 * Captures are the reason a structural query beats a text search at auditing rather than merely
 * at finding: the key array of a `firstOrCreate` call, extracted verbatim, is a value that can
 * be joined against the database's own unique indexes. The tool does not need to know about
 * databases to make that possible — it only has to hand back something joinable.
 */
final class Captures
{
    /** @var array<string, Node> */
    private array $nodes = [];

    public function bind(string $name, Node $node): void
    {
        $this->nodes[$name] ??= $node;
    }

    public function has(string $name): bool
    {
        return isset($this->nodes[$name]);
    }

    public function node(string $name): ?Node
    {
        return $this->nodes[$name] ?? null;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->nodes);
    }

    /**
     * The original bytes of each binding, collapsed to one line.
     *
     * Where a capture is a complex node the text form is a rendering rather than the thing
     * itself; `--json` carries the exact source so a consumer that needs fidelity has it.
     *
     * @return array<string, string>
     */
    public function text(SourceFile $file, int $budget = 60): array
    {
        $values = [];

        foreach ($this->nodes as $name => $node) {
            $source = $this->source($file, $node);
            $flat = trim((string) preg_replace('/\s+/', ' ', $source));

            $values[$name] = strlen($flat) <= $budget
                ? $flat
                : mb_strcut($flat, 0, $budget, 'UTF-8').'…';
        }

        return $values;
    }

    /**
     * @return array<string, string>
     */
    public function raw(SourceFile $file): array
    {
        $values = [];

        foreach ($this->nodes as $name => $node) {
            $values[$name] = $this->source($file, $node);
        }

        return $values;
    }

    private function source(SourceFile $file, Node $node): string
    {
        $start = $node->getStartFilePos();
        $end = $node->getEndFilePos();

        return $start < 0 || $end < $start ? '' : $file->slice($start, $end);
    }
}

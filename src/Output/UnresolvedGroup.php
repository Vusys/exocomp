<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Output;

/**
 * Unresolved rows that share a reason, counted, with a few examples.
 *
 * A search for a common name can leave thousands of hits unresolved, nearly all for two or three
 * reasons. Listed one per line, that buries the resolved answer above it under a screen of
 * `Model::factory()->create()`; summarised, the reader sees what kind of thing was left out and
 * how much of it, and can ask for the full list when it matters.
 */
final readonly class UnresolvedGroup
{
    /** Below this many unresolved rows, every one is listed. */
    public const int THRESHOLD = 10;

    public const int EXAMPLES = 3;

    /**
     * @param  list<Row>  $examples
     */
    public function __construct(
        public string $reason,
        public int $count,
        public array $examples,
    ) {}

    /**
     * Grouped by reason with the names taken out, so "Order declares no factory()" and
     * "Invoice declares no factory()" are one reason. Largest group first.
     *
     * @param  list<Row>  $rows
     * @return list<self>
     */
    public static function summarise(array $rows, int $examples = self::EXAMPLES): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $groups[self::reasonOf($row)][] = $row;
        }

        uasort($groups, static fn (array $a, array $b): int => count($b) <=> count($a));

        $summary = [];

        foreach ($groups as $reason => $members) {
            $summary[] = new self($reason, count($members), array_slice($members, 0, $examples));
        }

        return $summary;
    }

    private static function reasonOf(Row $row): string
    {
        $reason = (string) $row->note;
        $reason = (string) preg_replace('/\b[A-Za-z_]\w*(?:\\\\\w+)+/', '<class>', $reason);

        return (string) preg_replace('/\b(?!__)\w+\(\)/', '<method>()', $reason);
    }
}

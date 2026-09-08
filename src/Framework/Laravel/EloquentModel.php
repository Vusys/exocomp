<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Framework\Laravel;

/**
 * Everything the profile could read about one model.
 *
 * The split between what was read and what was declined is the whole shape of the answer: a
 * `morphTo` with no target and a `$casts` entry computed at runtime are not omissions, they are
 * findings, and they belong in the output rather than in a gap.
 */
final readonly class EloquentModel
{
    /**
     * @param  list<ModelFact>  $facts
     * @param  list<ModelFact>  $declined  things present in the source that could not be read statically
     * @param  list<string>  $notes
     */
    public function __construct(
        public string $fqn,
        public string $path,
        public int $line,
        public array $facts,
        public array $declined = [],
        public array $notes = [],
    ) {}

    /**
     * @return list<ModelFact>
     */
    public function group(string $group): array
    {
        return array_values(array_filter($this->facts, static fn (ModelFact $fact): bool => $fact->group === $group));
    }

    /**
     * @return list<string>
     */
    public function groups(): array
    {
        $seen = [];

        foreach ($this->facts as $fact) {
            $seen[$fact->group] = true;
        }

        return array_keys($seen);
    }
}

<?php

declare(strict_types=1);

namespace Fixtures\TierB;

use Fixtures\TierA\Support\Repository;

/**
 * Call shapes a structural query is supposed to tell apart, and a text search is not.
 *
 * Every `record()` below matches a grep for `record(`. Only two of them pass a non-literal
 * first argument, and only one of those is inside a method the caller might have scoped to —
 * which is the distinction the piped-grep idiom was approximating by hand.
 */
final class CallSites
{
    public function __construct(private readonly Repository $repository) {}

    public function withLiterals(string $name): void
    {
        $this->record('orders.created', 1);
        $this->record('orders.failed', 2);
    }

    public function withVariables(string $key, int $count): void
    {
        $this->record($key, $count);
        $this->record($key.'.suffix', $count);
    }

    protected function record(string $key, int $value): void {}

    public function lookups(string $uuid): ?object
    {
        return $this->repository->find(1) ?? $this->repository->findByUuid($uuid);
    }

    /**
     * @deprecated use lookups() instead
     */
    public function oldLookup(string $uuid): ?object
    {
        return $this->repository->findByUuid($uuid);
    }
}

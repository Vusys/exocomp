<?php

declare(strict_types=1);

namespace Fixtures\TierA;

use Fixtures\TierA\Support\Repository;

/**
 * The ordinary case: a typed class with the shapes that make up most real code.
 */
final class Baseline extends AbstractBase implements Contract
{
    use Loggable;

    public const string DEFAULT_LABEL = 'baseline';

    protected const int RETRIES = 3;

    private static int $instances = 0;

    public function __construct(
        private readonly Repository $repository,
        protected string $label = self::DEFAULT_LABEL,
    ) {}

    /**
     * Documented so that `show` has a docblock to include in the span.
     */
    public function greet(string $name, int $times = 1): string
    {
        return str_repeat("hello {$name}", $times);
    }

    public function repository(): Repository
    {
        return $this->repository;
    }

    protected function retries(): int
    {
        return self::RETRIES;
    }

    private function reset(): void
    {
        self::$instances = 0;
    }
}

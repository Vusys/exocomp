<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Support;

final readonly class Run
{
    public function __construct(
        public int $exitCode,
        public string $output,
        public string $errors,
    ) {}

    /**
     * @return list<string>
     */
    public function lines(): array
    {
        return explode("\n", rtrim($this->output, "\n"));
    }
}

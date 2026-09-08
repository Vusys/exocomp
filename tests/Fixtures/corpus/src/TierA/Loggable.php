<?php

declare(strict_types=1);

namespace Fixtures\TierA;

trait Loggable
{
    protected array $log = [];

    public function log(string $message): void
    {
        $this->log[] = $message;
    }
}

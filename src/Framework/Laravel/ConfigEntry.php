<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Framework\Laravel;

use Vusys\Exocomp\Symbol\Confidence;

/**
 * One config key, either where it is defined or where it is read.
 */
final readonly class ConfigEntry
{
    public function __construct(
        public string $key,
        public ?string $value,
        public string $path,
        public int $line,
        public Confidence $confidence,
        public string $kind,
        public ?string $note = null,
    ) {}
}

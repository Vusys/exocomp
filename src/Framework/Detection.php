<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Framework;

/**
 * What was found, and what proved it.
 *
 * The evidence is carried rather than discarded because framework answers are only as
 * trustworthy as the guess that a framework is present at all. A command that says "this is
 * not a Laravel project" has to be able to say what it looked at, or the caller's only recourse
 * is to disbelieve the tool.
 */
final readonly class Detection
{
    public function __construct(
        public string $framework,
        public ?string $version,
        public string $evidence,
    ) {}

    public function describe(): string
    {
        return $this->version === null
            ? sprintf('%s (%s)', $this->framework, $this->evidence)
            : sprintf('%s %s (%s)', $this->framework, $this->version, $this->evidence);
    }
}

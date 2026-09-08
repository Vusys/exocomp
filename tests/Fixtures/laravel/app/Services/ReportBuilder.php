<?php

declare(strict_types=1);

namespace App\Services;

final class ReportBuilder
{
    public function __construct(private readonly string $format = 'csv') {}

    public function build(): string
    {
        return $this->format;
    }
}

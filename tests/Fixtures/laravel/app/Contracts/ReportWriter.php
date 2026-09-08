<?php

declare(strict_types=1);

namespace App\Contracts;

interface ReportWriter
{
    public function write(string $path): bool;
}

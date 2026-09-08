<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\ReportWriter;

final class CsvReportWriter implements ReportWriter
{
    public function write(string $path): bool
    {
        return $path !== '';
    }
}

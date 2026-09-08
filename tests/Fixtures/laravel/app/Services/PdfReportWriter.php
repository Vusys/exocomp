<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\ReportWriter;

final class PdfReportWriter implements ReportWriter
{
    public function write(string $path): bool
    {
        return str_ends_with($path, '.pdf');
    }
}

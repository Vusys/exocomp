<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\ReportWriter;

/**
 * A third implementation, so the interface is a fan-out rather than a pair. Nothing at a call
 * site on `ReportWriter` says which of the three runs, and a walk that followed only some of
 * them without saying so would read as though there were only some.
 */
final class NullReportWriter implements ReportWriter
{
    public function write(string $path): bool
    {
        return $path !== '';
    }
}

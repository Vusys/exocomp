<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\ReportWriter;
use App\Facades\Reporting;

/**
 * Two joints in one method, and the language sees neither.
 *
 * `Reporting::build()` is a static call on a class that declares no `build()`; what runs is a
 * class named in a provider, three hops away. `app(ReportWriter::class)->write()` is a call on
 * `mixed`, because that is what the container's signature can honestly promise — the class that
 * answers is decided at boot.
 */
final class ReportDispatcher
{
    public function run(): string
    {
        $built = Reporting::build();

        return $built.app(ReportWriter::class)->write('summary.csv');
    }
}

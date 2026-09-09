<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\ReportBuilder;
use Illuminate\Support\ServiceProvider;

/**
 * A macro registration. The name is a literal and the body is readable, so the tool can say
 * where it came from — and that is all it can say, because whether this provider has run on the
 * path being asked about is not something the source records.
 */
final class MacroServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        ReportBuilder::macro('summarise', fn (): string => 'summary');

        // A computed name registers nothing findable, and must produce nothing rather than a guess.
        ReportBuilder::macro($this->name(), fn (): string => 'other');
    }

    private function name(): string
    {
        return 'computed';
    }
}

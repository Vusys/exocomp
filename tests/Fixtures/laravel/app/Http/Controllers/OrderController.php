<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\ReportWriter;

final class OrderController
{
    /**
     * Asks for the interface and gets PdfReportWriter rather than the default, because of a
     * contextual binding declared in a provider. Nothing at this end says so.
     */
    public function __construct(private readonly ReportWriter $writer) {}

    public function index(): string
    {
        return $this->writer->write('orders.pdf') ? 'ok' : 'failed';
    }

    public function show(): string
    {
        return 'one order';
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\ReportWriter;
use App\Models\Order;
use App\Services\OrderService;

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

    /**
     * The endpoint an audit is actually about: a route, two calls, and a queued job that runs
     * somewhere else entirely. Reading this file tells you none of that.
     */
    public function store(OrderService $orders): string
    {
        $orders->place(new Order);

        return 'created';
    }
}

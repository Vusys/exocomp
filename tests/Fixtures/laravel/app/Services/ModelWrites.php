<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;

/**
 * Writes whose observers run code the call site never names.
 */
final class ModelWrites
{
    public function saveOrder(Order $order): void
    {
        $order->save();
    }

    public function deleteOrder(Order $order): void
    {
        $order->delete();
    }

    public function renameCustomer(Customer $customer): void
    {
        $customer->update(['name' => 'renamed']);
    }

    public function deleteInvoice(Invoice $invoice): void
    {
        $invoice->delete();
    }

    public function massUpdateOrders(): void
    {
        Order::where('id', 1)->update(['reference' => 'x']);
    }

    public function sendInvoice(Invoice $invoice): void
    {
        $invoice->send();
    }
}

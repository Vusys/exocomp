<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Comment;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderLine;
use Illuminate\Support\Facades\Gate;

/**
 * Authorization checks, each of which runs a policy method the call site never names.
 */
final class AuthorizedActions
{
    public function updateOrder(Order $order): void
    {
        $this->authorize('update', $order);
    }

    public function createOrder(): void
    {
        $this->authorize('create', Order::class);
    }

    public function forceDeleteCustomer(Customer $customer): void
    {
        Gate::authorize('force-delete', $customer);
    }

    public function viewInvoice($user, Invoice $invoice): bool
    {
        return $user->can('view', $invoice);
    }

    public function deleteLine(OrderLine $line): void
    {
        $this->authorize('delete', [$line]);
    }

    public function unknownSubject($thing): void
    {
        $this->authorize('update', $thing);
    }

    public function noPolicy(Comment $comment): void
    {
        $this->authorize('view', $comment);
    }

    public function abilityInAVariable(Order $order, string $ability): void
    {
        $this->authorize($ability, $order);
    }

    private function authorize(string $ability, mixed $arguments): void {}
}

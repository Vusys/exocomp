<?php

declare(strict_types=1);

namespace App\Inference;

use Illuminate\Support\Collection;

/**
 * Types written in docblocks the resolver used to drop or misread.
 */
final class TypeProbes
{
    /** @param Box<Gateway> $box */
    public function boxed(Box $box): void
    {
        $box->get()->refund(1);
    }

    /** @param Collection<int, Gateway> $gateways */
    public function iterated(Collection $gateways): void
    {
        foreach ($gateways as $gateway) {
            $gateway->refund(2);
        }
    }

    /** @param Collection<int, Gateway> $gateways */
    public function firstOfCollection(Collection $gateways): void
    {
        $gateways->filter()->first()->refund(3);
    }

    /** @param Collection<int, Gateway> $gateways */
    public function callbackOfCollection(Collection $gateways): void
    {
        $gateways->each(fn ($gateway) => $gateway->refund(4));
    }

    public function destructured(): void
    {
        ['gateway' => $gateway] = $this->pair();
        $gateway->refund(5);
    }

    public function fluentFromSubclass(GatewayRepository $repository): void
    {
        $repository->fluent()->only()->refund(6);
    }

    public function templateLeak(GatewayRepository $repository): void
    {
        foreach ($repository->all() as $item) {
            $item->refund(7);
        }
    }

    public function missingImport(StripeGateway $gateway): void
    {
        $gateway->refund(8);
    }

    public function doubled(): void
    {
        $this->createMock(Gateway::class)->refund(9);
    }

    public function createMock(string $class): object
    {
        return new $class;
    }

    /** @return array{gateway: Gateway} */
    private function pair(): array
    {
        return ['gateway' => new Gateway];
    }
}

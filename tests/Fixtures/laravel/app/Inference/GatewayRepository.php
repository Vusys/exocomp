<?php

declare(strict_types=1);

namespace App\Inference;

/**
 * @extends Repository<Gateway>
 */
final class GatewayRepository extends Repository
{
    public function find(int $id): object
    {
        return $this->lookup($id);
    }

    private function lookup(int $id): mixed
    {
        return $id;
    }

    public function only(): Gateway
    {
        return new Gateway;
    }
}

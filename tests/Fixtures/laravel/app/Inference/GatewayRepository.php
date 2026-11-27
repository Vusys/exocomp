<?php

declare(strict_types=1);

namespace App\Inference;

/**
 * @extends Repository<Gateway>
 */
final class GatewayRepository extends Repository
{
    public function only(): Gateway
    {
        return new Gateway;
    }
}

<?php

declare(strict_types=1);

namespace App\Inference;

use RuntimeException;

final class GatewayFailed extends RuntimeException
{
    public function gateway(): Gateway
    {
        return new Gateway;
    }
}

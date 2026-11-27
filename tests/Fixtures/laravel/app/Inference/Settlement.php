<?php

declare(strict_types=1);

namespace App\Inference;

enum Settlement: string
{
    case Paid = 'paid';

    public function gateway(): Gateway
    {
        return new Gateway;
    }
}

<?php

declare(strict_types=1);

namespace App\Inference;

final class Holder
{
    public function make(): Gateway
    {
        return new Gateway;
    }
}

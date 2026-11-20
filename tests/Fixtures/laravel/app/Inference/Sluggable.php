<?php

declare(strict_types=1);

namespace App\Inference;

final class Sluggable
{
    use FindsBySlug;

    public function gateway(): Gateway
    {
        return new Gateway;
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

enum OrderStatus: string
{
    case Draft = 'draft';
    case Placed = 'placed';
}

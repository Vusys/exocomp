<?php

declare(strict_types=1);

namespace App\Models;

use App\Policies\LineItemRules;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * Declares no `$table`, so its name comes from the framework's convention:
 * `OrderLine` becomes `order_lines`.
 */
#[UsePolicy(LineItemRules::class)]
final class OrderLine extends Model
{
    protected $fillable = ['order_id', 'sku', 'position'];
}

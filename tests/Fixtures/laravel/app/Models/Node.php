<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasTree;
use Illuminate\Database\Eloquent\Model;

/**
 * The class that uses the tree trait, and so what `self` names inside it.
 */
abstract class Node extends Model
{
    use HasTree;
}

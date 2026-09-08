<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An irregular plural. The convention makes this `people`, which is why the profile uses the
 * framework's own inflector rather than an approximation of one.
 */
final class Person extends Model {}

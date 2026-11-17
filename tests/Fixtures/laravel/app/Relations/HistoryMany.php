<?php

declare(strict_types=1);

namespace App\Relations;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A package's own relation class, as temporal and tree packages ship them. Nothing about the
 * name says it is a relation; its ancestry does.
 */
class HistoryMany extends HasMany {}

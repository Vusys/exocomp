<?php

declare(strict_types=1);

use App\Inference\Gateway;

// Closures outside any class, as a Pest test file or a route file has: each is its own caller.
$first = fn (Gateway $g) => $g->refund(3);
$second = fn (Gateway $g) => $g->refund(4);

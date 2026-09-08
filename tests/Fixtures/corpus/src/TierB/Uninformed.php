<?php

declare(strict_types=1);

namespace Fixtures\TierB;

use Vendor\Absent\BaseThing;

/**
 * Extends something that is not in the corpus at all — the shape of every application class
 * that extends a framework base. Its own declarations are a small fraction of its real API, so
 * listing them without comment would be the most ordinary way to mislead.
 */
final class Uninformed extends BaseThing
{
    public function mine(): void {}
}

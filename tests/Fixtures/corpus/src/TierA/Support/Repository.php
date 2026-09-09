<?php

declare(strict_types=1);

namespace Fixtures\TierA\Support;

final class Repository
{
    public function find(int $id): ?object
    {
        return null;
    }

    /*
     * There is deliberately no `findByUuid()` here, and `TierB\CallSites` deliberately calls
     * one. A final class with no parent is the only shape where an absent member can be
     * *proved* absent, so this is the fixture that holds the tool to the difference between
     * "the receiver is this class" and "this class has that member" — it used to report the
     * second as resolved on the strength of the first.
     */
}

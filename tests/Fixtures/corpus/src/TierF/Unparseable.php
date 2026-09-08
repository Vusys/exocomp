<?php

declare(strict_types=1);

namespace Fixtures\TierF;

// Deliberately unparseable. One broken file must degrade that file and never abort a
// corpus-wide operation — an agent frequently queries a file it has just half-edited, so the
// parser fails exactly when help is most wanted.
final class Unparseable
{
    public function halfWritten(): void
    {
        $this->

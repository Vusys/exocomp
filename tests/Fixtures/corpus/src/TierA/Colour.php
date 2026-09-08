<?php

declare(strict_types=1);

namespace Fixtures\TierA;

enum Colour: string implements Describable
{
    case Red = 'red';

    case Green = 'green';

    public const string FALLBACK = 'red';

    public function describe(): string
    {
        return $this->value;
    }
}

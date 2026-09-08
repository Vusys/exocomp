<?php

declare(strict_types=1);

namespace Fixtures\TierB\First {
    final class Inhabitant
    {
        public function where(): string
        {
            return __NAMESPACE__;
        }
    }
}

namespace Fixtures\TierB\Second {
    final class Inhabitant
    {
        public function where(): string
        {
            return __NAMESPACE__;
        }
    }
}

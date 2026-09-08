<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Console;

use Symfony\Component\Console\Input\InputInterface;

/**
 * `InputInterface::getArgument()`/`getOption()` return `mixed` (they also cover array-valued
 * options), so a cast cannot be narrowed without a genuine runtime check. These do that check
 * rather than overriding the inferred type with a `@var` — every command's arguments and
 * options really are scalar, so the typed branch is always the one taken and the fallback
 * matters only to the analyser.
 */
trait TypedInput
{
    protected function stringArgument(InputInterface $input, string $name): ?string
    {
        $value = $input->getArgument($name);

        return is_string($value) ? $value : null;
    }

    protected function stringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return is_string($value) ? $value : null;
    }

    protected function intOption(InputInterface $input, string $name, int $default): int
    {
        $value = $input->getOption($name);

        return is_numeric($value) ? (int) $value : $default;
    }

    protected function boolOption(InputInterface $input, string $name): bool
    {
        return $input->getOption($name) === true;
    }
}

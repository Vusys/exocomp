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

    /**
     * An `IS_ARRAY` argument, narrowed to the strings it really holds.
     *
     * @return list<string>
     */
    protected function stringListArgument(InputInterface $input, string $name): array
    {
        $value = $input->getArgument($name);

        return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
    }

    protected function stringOption(InputInterface $input, string $name): ?string
    {
        // A command that does not offer an option (an index-backed one has no `--rev`) reads it
        // as not given, so the shared code that asks need not know which command it is in.
        if (! $input->hasOption($name)) {
            return null;
        }

        $value = $input->getOption($name);

        return is_string($value) ? $value : null;
    }

    protected function intOption(InputInterface $input, string $name, int $default): int
    {
        $value = $input->getOption($name);

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @return list<string>
     */
    protected function arrayOption(InputInterface $input, string $name): array
    {
        $value = $input->getOption($name);

        return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
    }

    protected function boolOption(InputInterface $input, string $name): bool
    {
        return $input->hasOption($name) && $input->getOption($name) === true;
    }
}

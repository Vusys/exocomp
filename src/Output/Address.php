<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Output;

/**
 * The `path:line:col` prefix every cross-file record leads with.
 *
 * It is grep's convention, deliberately: terminals and editors already turn it into a link,
 * `cut -d:` already splits it, and a reader already knows what it means. Inventing a different
 * one would cost all of that for nothing.
 */
final class Address
{
    public static function of(string $path, ?int $line = null, ?int $column = null): string
    {
        if ($line === null) {
            return $path;
        }

        return $column === null ? $path.':'.$line : $path.':'.$line.':'.$column;
    }

    /**
     * Pads a set of addresses to a common width so the text after them lines up, without
     * letting one very long path push every other line off the screen.
     *
     * @param  list<string>  $addresses
     */
    public static function width(array $addresses, int $ceiling = 60): int
    {
        $width = 0;

        foreach ($addresses as $address) {
            $length = strlen($address);

            if ($length <= $ceiling) {
                $width = max($width, $length);
            }
        }

        return $width;
    }
}

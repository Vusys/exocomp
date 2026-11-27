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
    /**
     * A line of 0 or less is no line: a record placed at a file but not in it, such as a route
     * known only from the table Laravel builds. `path:0` reads as a position and is not one.
     */
    public static function of(string $path, ?int $line = null, ?int $column = null): string
    {
        if ($line === null || $line <= 0) {
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

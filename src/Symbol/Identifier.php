<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Symbol;

/**
 * How a PHP identifier is folded for a case-insensitive comparison.
 *
 * PHP folds class, function and method names using **ASCII rules only**: `Foo` and `foo` name
 * the same class, but `ÉFoo` and `éFoo` name two different ones, because the engine leaves
 * every byte above 0x7F alone. Folding with `mb_strtolower` instead looks more careful and is
 * in fact wrong — it maps `İ` onto `i̇`, collapsing two classes PHP keeps apart, and an index
 * that collapses them answers about the wrong one with full confidence.
 *
 * That is the failure this tool exists to avoid, so the rule lives in one named place rather
 * than being spelled out at each of the twenty-odd sites that folds a name.
 *
 * `strtolower` has been locale-independent and ASCII-only since PHP 8.2, which is what makes it
 * the correct primitive here rather than merely the cheaper one.
 */
final class Identifier
{
    public static function fold(string $name): string
    {
        return strtolower($name);
    }
}

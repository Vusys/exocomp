<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Query;

/**
 * Namespace globbing, in the shape a caller already expects from a shell.
 *
 * `App\Domain\**` is everything under a namespace; `App\*\Repository` is one segment wide. The
 * literal head of a glob is also used to narrow which files are worth opening at all, which is
 * what makes `--in` a way of making a corpus-wide query cheap rather than merely of filtering
 * its results.
 */
final class NamespaceGlob
{
    public static function matches(string $glob, string $subject): bool
    {
        $pattern = preg_quote(trim($glob, '\\'), '/');
        $pattern = str_replace(['\\*\\*', '\\*'], ['@@DEEP@@', '[^\\\\\\\\]*'], $pattern);
        $pattern = str_replace('@@DEEP@@', '.*', $pattern);

        return preg_match('/^'.$pattern.'$/i', trim($subject, '\\')) === 1;
    }

    /**
     * The part of a glob before its first wildcard — a prefix every match must start with, and
     * therefore a cheap way to skip files that cannot contain one.
     */
    public static function literalPrefix(string $glob): string
    {
        $glob = trim($glob, '\\');
        $position = strcspn($glob, '*?');

        return substr($glob, 0, $position);
    }
}

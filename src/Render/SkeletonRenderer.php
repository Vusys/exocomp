<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Render;

use Vusys\Exocomp\Analysis\Declaration;
use Vusys\Exocomp\Symbol\SymbolKind;

/**
 * A class rendered as the PHP it is, with every body replaced by `…`.
 *
 * This is `show --without-bodies`: the shape of a class at a small fraction of its tokens,
 * still readable as PHP rather than as a table. The `…` is deliberate — it says a body was
 * removed, where an empty `{}` would claim the method does nothing.
 */
final class SkeletonRenderer
{
    private const string INDENT = '    ';

    public static function render(Declaration $declaration): string
    {
        if (! $declaration->isClassLike()) {
            return self::memberLine($declaration, '');
        }

        $lines = [$declaration->fullSignature, '{'];

        if ($declaration->traits !== []) {
            $lines[] = self::INDENT.'use '.implode(', ', $declaration->traits).';';
        }

        foreach ($declaration->members as $member) {
            // Promoted properties are already visible in the constructor signature; repeating
            // them as pseudo-property lines would double-count the class's API.
            if ($member->isPromoted) {
                continue;
            }

            $lines[] = self::memberLine($member, self::INDENT);
        }

        $lines[] = '}';

        return implode("\n", $lines);
    }

    private static function memberLine(Declaration $member, string $indent): string
    {
        $terminator = match (true) {
            $member->kind === SymbolKind::Method && $member->hasBody => ' { … }',
            $member->kind === SymbolKind::Property && $member->hasBody => '',
            $member->kind === SymbolKind::FunctionDecl => ' { … }',
            default => ';',
        };

        return $indent.$member->fullSignature.$terminator;
    }
}

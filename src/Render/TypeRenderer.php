<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Render;

use PhpParser\Node;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\UnionType;

/**
 * Renders a type node short, for the dense one-line records.
 *
 * `HasMany` carries the information that `Illuminate\Database\Eloquent\Relations\HasMany`
 * carries, at a fraction of the tokens, repeated down every line of every outline. Where the
 * original spelling matters — skeleton output, which should read as the PHP it came from —
 * {@see SignatureRenderer} slices the source bytes instead.
 */
final class TypeRenderer
{
    public static function short(?Node $type): ?string
    {
        return match (true) {
            ! $type instanceof Node => null,
            $type instanceof Identifier => $type->toString(),
            $type instanceof Name => $type->getLast(),
            $type instanceof NullableType => '?'.self::short($type->type),
            $type instanceof UnionType => self::union($type),
            $type instanceof IntersectionType => self::intersection($type),
            default => null,
        };
    }

    /**
     * A union member that is itself an intersection is a DNF type, and needs its parentheses
     * back: flattening `(A&B)|null` to `A&B|null` would change what it means.
     */
    private static function union(UnionType $type): string
    {
        $parts = array_map(static function (Node $part): string {
            $text = self::short($part) ?? 'mixed';

            return $part instanceof IntersectionType ? '('.$text.')' : $text;
        }, $type->types);

        return implode('|', $parts);
    }

    private static function intersection(IntersectionType $type): string
    {
        return implode('&', array_map(static fn (Node $part): string => self::short($part) ?? 'mixed', $type->types));
    }
}

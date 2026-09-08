<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Analysis;

use PhpParser\Node;

/**
 * Reads the value of an expression that has one, and admits it when there is not.
 *
 * Framework configuration is written as literals — `protected $table = 'orders'`,
 * `'driver' => env('QUEUE_CONNECTION', 'sync')` — and reading those literally is the whole of
 * what static extraction can honestly do. Anything computed comes back as null, so the caller
 * reports "declared here, value not statically known" rather than a plausible-looking guess.
 */
final class LiteralValue
{
    public static function scalar(Node\Expr $expression): string|int|float|bool|null
    {
        return match (true) {
            $expression instanceof Node\Scalar\String_ => $expression->value,
            $expression instanceof Node\Scalar\Int_ => $expression->value,
            $expression instanceof Node\Scalar\Float_ => $expression->value,
            $expression instanceof Node\Expr\ConstFetch => self::constant($expression),
            $expression instanceof Node\Expr\ClassConstFetch => self::classConstant($expression),
            default => null,
        };
    }

    /**
     * The fully-qualified class name behind `X::class`, or behind a string that is spelled like
     * one. Both forms appear constantly in framework code and both name a class; only the
     * first is checkable by the engine, which is why the caller is told which it got.
     */
    public static function className(Node\Expr $expression): ?string
    {
        if ($expression instanceof Node\Expr\ClassConstFetch
            && $expression->class instanceof Node\Name
            && $expression->name instanceof Node\Identifier
            && strcasecmp($expression->name->toString(), 'class') === 0) {
            return Analyser::resolvedName($expression->class);
        }

        if ($expression instanceof Node\Scalar\String_ && str_contains($expression->value, '\\')) {
            return ltrim($expression->value, '\\');
        }

        return null;
    }

    /**
     * A list of literal values, with anything non-literal dropped and counted by the caller.
     *
     * @return list<array{value: string|int|float|bool|null, line: int, literal: bool}>
     */
    public static function items(Node\Expr $expression): array
    {
        if (! $expression instanceof Node\Expr\Array_) {
            return [];
        }

        $items = [];

        foreach ($expression->items as $item) {
            $value = self::scalar($item->value) ?? self::className($item->value);

            $items[] = [
                'value' => $value,
                'line' => $item->getStartLine(),
                'literal' => $value !== null,
            ];
        }

        return $items;
    }

    /**
     * An array written as `key => value`, keeping both sides and the line each pair sits on.
     *
     * @return list<array{key: string, value: ?string, line: int}>
     */
    public static function pairs(Node\Expr $expression): array
    {
        if (! $expression instanceof Node\Expr\Array_) {
            return [];
        }

        $pairs = [];

        foreach ($expression->items as $item) {
            if (! $item->key instanceof Node\Expr) {
                continue;
            }

            $key = self::scalar($item->key);

            if (! is_string($key)) {
                continue;
            }

            $value = self::className($item->value) ?? self::scalar($item->value);

            $pairs[] = [
                'key' => $key,
                'value' => $value === null ? null : (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value),
                'line' => $item->getStartLine(),
            ];
        }

        return $pairs;
    }

    private static function constant(Node\Expr\ConstFetch $expression): string|bool|null
    {
        return match (strtolower($expression->name->toString())) {
            'true' => true,
            'false' => false,
            'null' => null,
            default => $expression->name->toString(),
        };
    }

    private static function classConstant(Node\Expr\ClassConstFetch $expression): ?string
    {
        return self::className($expression);
    }
}

<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Rules;

use InvalidArgumentException;
use Vusys\Exocomp\Query\Pattern;
use Vusys\Exocomp\Query\Predicates;

/**
 * One saved query, with a name and something to say when it matches.
 *
 * A sweep written once should be runnable again. That is what turns a one-off audit — the
 * cross-tenant scoping check, the orphaning check, the two-write-paths check — into a permanent
 * guard, and it is probably the strongest long-term argument for the tool: without it, every
 * such audit decays the moment the person who ran it moves on.
 */
final readonly class Rule
{
    public function __construct(
        public string $id,
        public string $message,
        public Pattern $pattern,
        public Predicates $predicates,
    ) {}

    /**
     * @param  array<string, mixed>  $definition
     */
    public static function fromArray(array $definition): self
    {
        $id = self::string($definition, 'id');
        $pattern = self::string($definition, 'pattern');

        if ($id === null || $pattern === null) {
            throw new InvalidArgumentException('every rule needs an "id" and a "pattern"');
        }

        return new self(
            id: $id,
            message: self::string($definition, 'message') ?? $id,
            pattern: Pattern::parse($pattern),
            predicates: new Predicates(
                in: self::strings($definition, 'in'),
                notIn: self::strings($definition, 'not-in'),
                annotated: self::strings($definition, 'annotated'),
                attributes: self::strings($definition, 'has-attribute'),
                literalArguments: self::integers($definition, 'arg-is-literal'),
                nonLiteralArguments: self::integers($definition, 'arg-is-not-literal'),
                paramCount: self::integer($definition, 'param-count'),
                visibility: self::string($definition, 'visibility'),
                returns: self::string($definition, 'returns'),
                usesTrait: self::strings($definition, 'uses-trait'),
                extends: self::strings($definition, 'extends'),
                implements: self::strings($definition, 'implements'),
                receiverTypes: self::strings($definition, 'receiver-type'),
                receiverImplements: self::strings($definition, 'receiver-implements'),
                receiverExtends: self::strings($definition, 'receiver-extends'),
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private static function string(array $definition, string $key): ?string
    {
        $value = $definition[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private static function integer(array $definition, string $key): ?int
    {
        $value = $definition[$key] ?? null;

        return is_int($value) ? $value : null;
    }

    /**
     * A single value where a list is expected is accepted, because writing `"in": "App\\**"`
     * is the obvious thing to try and failing on it would be pedantry.
     *
     * @param  array<string, mixed>  $definition
     * @return list<string>
     */
    private static function strings(array $definition, string $key): array
    {
        $value = $definition[$key] ?? [];
        $values = is_array($value) ? $value : [$value];

        return array_values(array_filter($values, is_string(...)));
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return list<int>
     */
    private static function integers(array $definition, string $key): array
    {
        $value = $definition[$key] ?? [];
        $values = is_array($value) ? $value : [$value];

        return array_values(array_map(intval(...), array_filter($values, is_numeric(...))));
    }
}

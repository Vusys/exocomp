<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Inference;

use PHPStan\PhpDocParser\Ast\PhpDoc\ParamTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\ReturnTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\VarTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;

/**
 * The types a docblock claims, read with a real parser rather than a regex.
 *
 * Docblock syntax is a grammar — generics, unions, conditional types, array shapes — and
 * approximating a grammar with a pattern is how a tool acquires a long tail of quiet wrong
 * answers. `phpstan/phpdoc-parser` already exists and already handles all of it, so the only
 * work left here is deciding which of what it reports is safe to use.
 *
 * That decision is narrow on purpose: a single class-like name, or a nullable one. Anything
 * else — a union, a generic, an array shape — cannot name one receiver, and picking a member
 * of it would be a guess.
 */
final readonly class DocBlockTypes
{
    private PhpDocParser $parser;

    private Lexer $lexer;

    public function __construct()
    {
        $config = new ParserConfig([]);
        $this->lexer = new Lexer($config);
        $this->parser = new PhpDocParser($config, new TypeParser($config, new ConstExprParser($config)), new ConstExprParser($config));
    }

    /**
     * @return array{params: array<string, string>, return: ?string, var: ?string, vars: array<string, string>}
     */
    public function read(string $docblock): array
    {
        if (trim($docblock) === '') {
            return ['params' => [], 'return' => null, 'var' => null, 'vars' => []];
        }

        $tokens = new TokenIterator($this->lexer->tokenize($docblock));
        $node = $this->parser->parse($tokens);

        $params = [];
        $return = null;
        $var = null;
        $vars = [];

        foreach ($node->getTags() as $tag) {
            $value = $tag->value;

            if ($value instanceof ParamTagValueNode) {
                $name = self::nameOf($value->type);

                if ($name !== null) {
                    $params[ltrim($value->parameterName, '$')] = $name;
                }

                continue;
            }

            if ($value instanceof ReturnTagValueNode) {
                $return ??= self::nameOf($value->type);

                continue;
            }

            if ($value instanceof VarTagValueNode) {
                $name = self::nameOf($value->type);
                $var ??= $name;

                // `@var Foo $x` names the variable it is about, which is what lets an
                // annotation sitting above an arbitrary statement bind anything at all.
                if ($name !== null && $value->variableName !== '') {
                    $vars[ltrim($value->variableName, '$')] = $name;
                }
            }
        }

        return ['params' => $params, 'return' => $return, 'var' => $var, 'vars' => $vars];
    }

    /**
     * A bare identifier, or a nullable one. Everything else is left alone.
     */
    private static function nameOf(TypeNode $type): ?string
    {
        if ($type instanceof NullableTypeNode) {
            return self::nameOf($type->type);
        }

        if (! $type instanceof IdentifierTypeNode) {
            return null;
        }

        $name = $type->name;

        // Scalars and pseudo-types name no class, so there is no receiver to resolve through.
        return in_array(strtolower($name), [
            'int', 'integer', 'float', 'double', 'string', 'bool', 'boolean', 'array', 'object',
            'callable', 'iterable', 'mixed', 'void', 'null', 'never', 'true', 'false', 'resource',
        ], true) ? null : $name;
    }
}

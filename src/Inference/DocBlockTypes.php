<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Inference;

use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\MethodTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\MixinTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\ParamTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PropertyTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\ReturnTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\VarTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ThisTypeNode;
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
 * of it would be a guess. An array type is read only for what it says of its elements
 * ({@see elements()}), never as a receiver itself.
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
     * The members a class docblock claims it has, and the classes it says its magic forwards to.
     *
     * These are the `@method`, `@property` and `@mixin` tags, and they are the commonest form
     * magic takes in practice by a wide margin — a measured Laravel application carried 3,552
     * `@method` and 4,155 `@property` tags against a single `__call` body. They are read here
     * and nowhere else, so that everything downstream treats them as one kind of evidence.
     *
     * A member whose type is not a class name is still recorded, with a null type. "This member
     * exists and yields an int" is a different and better answer than "no such member", even
     * though there is no receiver to carry forward.
     *
     * @return array{methods: array<string, array{type: ?string, tag: string}>, properties: array<string, array{type: ?string, tag: string}>, mixins: list<string>}
     */
    public function members(string $docblock): array
    {
        $methods = [];
        $properties = [];
        $mixins = [];

        if (trim($docblock) === '') {
            return ['methods' => $methods, 'properties' => $properties, 'mixins' => $mixins];
        }

        $node = $this->parser->parse(new TokenIterator($this->lexer->tokenize($docblock)));

        foreach ($node->getTags() as $tag) {
            $value = $tag->value;

            if ($value instanceof MethodTagValueNode) {
                $methods[$value->methodName] = [
                    'type' => $value->returnType instanceof TypeNode ? self::nameOf($value->returnType) : null,
                    'tag' => $tag->name,
                ];

                continue;
            }

            if ($value instanceof PropertyTagValueNode) {
                // The tag is kept because `@property-read` and `@property` are different claims,
                // and a reader deciding whether to trust one wants to see which was written.
                $properties[ltrim($value->propertyName, '$')] = [
                    'type' => self::nameOf($value->type),
                    'tag' => $tag->name,
                ];

                continue;
            }

            if ($value instanceof MixinTagValueNode) {
                $name = self::nameOf($value->type);

                if ($name !== null) {
                    $mixins[] = $name;
                }
            }
        }

        return ['methods' => $methods, 'properties' => $properties, 'mixins' => $mixins];
    }

    /**
     * The classes the same tags document an array's elements as.
     *
     * `Page[]`, `list<Page>`, `array<string, Page>` and `iterable<Page>` name the element at any
     * key, kept under `*`; `array{book: Book, page: Page}` names one per key. An element that is
     * not a single class is left out, as {@see read()} leaves out a type that is not one.
     *
     * @return array{params: array<string, array<string, string>>, return: array<string, string>, var: array<string, string>, vars: array<string, array<string, string>>}
     */
    public function elements(string $docblock): array
    {
        $found = ['params' => [], 'return' => [], 'var' => [], 'vars' => []];

        if (trim($docblock) === '') {
            return $found;
        }

        foreach ($this->parser->parse(new TokenIterator($this->lexer->tokenize($docblock)))->getTags() as $tag) {
            $value = $tag->value;

            if ($value instanceof ParamTagValueNode) {
                $elements = self::elementsOf($value->type);

                if ($elements !== []) {
                    $found['params'][ltrim($value->parameterName, '$')] = $elements;
                }
            } elseif ($value instanceof ReturnTagValueNode && $found['return'] === []) {
                $found['return'] = self::elementsOf($value->type);
            } elseif ($value instanceof VarTagValueNode) {
                $elements = self::elementsOf($value->type);

                if ($found['var'] === []) {
                    $found['var'] = $elements;
                }

                if ($elements !== [] && $value->variableName !== '') {
                    $found['vars'][ltrim($value->variableName, '$')] = $elements;
                }
            }
        }

        return $found;
    }

    /**
     * @return array<string, string>
     */
    private static function elementsOf(TypeNode $type): array
    {
        if ($type instanceof NullableTypeNode) {
            return self::elementsOf($type->type);
        }

        $any = match (true) {
            $type instanceof ArrayTypeNode => $type->type,
            $type instanceof GenericTypeNode && $type->type instanceof IdentifierTypeNode && in_array(strtolower($type->type->name), ['array', 'list', 'non-empty-array', 'non-empty-list', 'iterable'], true) => $type->genericTypes[array_key_last($type->genericTypes)] ?? null,
            default => null,
        };

        if ($any instanceof TypeNode) {
            $name = self::nameOf($any);

            return $name === null ? [] : ['*' => $name];
        }

        if (! $type instanceof ArrayShapeNode) {
            return [];
        }

        $elements = [];

        foreach ($type->items as $position => $item) {
            $key = match (true) {
                $item->keyName === null => (string) $position,
                $item->keyName instanceof IdentifierTypeNode => $item->keyName->name,
                $item->keyName instanceof ConstExprStringNode, $item->keyName instanceof ConstExprIntegerNode => $item->keyName->value,
                default => null,
            };
            $name = self::nameOf($item->valueType);

            if ($key !== null && $name !== null) {
                $elements[$key] = $name;
            }
        }

        return $elements;
    }

    /**
     * A bare identifier, or a nullable one. Everything else is left alone.
     */
    private static function nameOf(TypeNode $type): ?string
    {
        if ($type instanceof NullableTypeNode) {
            return self::nameOf($type->type);
        }

        // `$this` is the receiver's class, as `static` is.
        if ($type instanceof ThisTypeNode) {
            return 'static';
        }

        // `static<TKey, TValue>`, `Builder<Order>`: the class is the generic's base; what its
        // parameters are is read separately, as elements.
        if ($type instanceof GenericTypeNode) {
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
            'list', 'non-empty-list', 'non-empty-array', 'array-key', 'class-string', 'positive-int',
            'negative-int', 'non-negative-int', 'numeric', 'scalar', 'non-empty-string', 'key-of', 'value-of',
        ], true) ? null : $name;
    }
}

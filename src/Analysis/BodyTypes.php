<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Analysis;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassMethod;
use Vusys\Exocomp\Inference\DocBlockTypes;

/**
 * The class a property holds, or a method returns, worked out from the class's own code where
 * nothing native declares it, and from its docblock where the code doesn't say.
 *
 * Code first, because a docblock is a claim and the code is what runs: a property every
 * assignment in the class fills with `new Logger`, or a method each of whose returns is
 * `$this->logger`, holds or returns a Logger whatever its comment says. The rules are narrow on
 * purpose. Every assignment, or every return, must name the same class, through `new`, `$this`,
 * a typed parameter, a typed property or a same-class method's declared type. One that names
 * nothing, or something else, leaves the code silent, and then the docblock is asked. Both
 * answers are inferences, never declarations: a public property may be assigned from outside the
 * class, and a subclass may override a method.
 */
final readonly class BodyTypes
{
    public function __construct(private DocBlockTypes $docblocks = new DocBlockTypes) {}

    /**
     * The marker an index record carries for a method documented to return its class's last
     * template, the type of what it holds: `@return TValue` on a collection.
     */
    public const string RECEIVER_ELEMENT = '@element';

    /**
     * @param  list<Import>  $imports
     * @return array<string, array{string, string}> member name (`$prop` or `method`) to [type, reason]
     */
    public function of(Stmt\ClassLike $class, string $classFqn, array $imports): array
    {
        // The class's `@template` names: placeholders, never classes.
        $templates = $this->docblocks->templates($class->getDocComment()?->getText() ?? '');
        $properties = [];
        $methods = [];

        foreach ($class->stmts as $statement) {
            if ($statement instanceof Stmt\Property) {
                foreach ($statement->props as $item) {
                    $properties[$item->name->toString()] = $statement;
                }
            } elseif ($statement instanceof ClassMethod) {
                $methods[strtolower($statement->name->toString())] = $statement;
            }
        }

        $found = [];
        $propertyTypes = [];

        foreach ($methods['__construct']->params ?? [] as $param) {
            if ($param->flags !== 0 && $param->var instanceof Expr\Variable && is_string($param->var->name) && $param->type !== null) {
                $propertyTypes[$param->var->name] = self::nativeClass($param->type);
            }
        }

        foreach ($properties as $name => $property) {
            if ($property->type instanceof Node) {
                $propertyTypes[$name] = self::nativeClass($property->type);

                continue;
            }

            $type = $this->assignedType($name, $property, $methods, $classFqn)
                ?? $this->documented($property, 'var', $classFqn, $imports, $templates);

            if ($type !== null) {
                $found['$'.$name] = $type;
                $propertyTypes[$name] = $type[0];
            }
        }

        // A property the class never declares but assigns, as older code does from its
        // constructor: PHP creates it on first assignment, and its assignments type it the same
        // way. Only one some assignment can type is reported, so it is never a bare guess.
        foreach ($this->undeclaredProperties($methods, $properties, $propertyTypes) as $name) {
            $type = $this->assignedType($name, null, $methods, $classFqn);

            if ($type !== null) {
                $found['$'.$name] = [$type[0], 'every assignment in the class; the property is not declared'];
                $propertyTypes[$name] = $type[0];
            }
        }

        foreach ($methods as $method) {
            // `self` natively and `@return static` in the docblock is the usual way to write a
            // fluent method before `static` was a return type: the docblock is the narrower claim.
            if ($method->returnType instanceof Node\Name && $method->returnType->toLowerString() === 'self') {
                $documented = $this->documented($method, 'return', $classFqn, $imports, $templates);

                if ($documented !== null && $documented[0] === 'static') {
                    $found[$method->name->toString()] = $documented;
                }

                continue;
            }

            if ($method->returnType instanceof Node || $method->stmts === null) {
                continue;
            }

            $type = $this->returnedType($method, $methods, $propertyTypes, $classFqn)
                ?? $this->documented($method, 'return', $classFqn, $imports, $templates);

            if ($type !== null) {
                $found[$method->name->toString()] = $type;
            }
        }

        return $found;
    }

    /**
     * What the docblock of an array property or method says its elements are: `@var Page[]`,
     * `@return array{book: Book, page: Page}`. Only where nothing native names a class, and only
     * from the docblock, because the code building an array rarely says what goes in it.
     *
     * @param  list<Import>  $imports
     * @return array<string, array<string, string>> member name (`$prop` or `method`) to elements
     */
    public function elementsOf(Stmt\ClassLike $class, string $classFqn, array $imports): array
    {
        $found = [];

        foreach ($class->stmts as $statement) {
            if ($statement instanceof Stmt\Property) {
                [$key, $type, $tag] = ['$'.$statement->props[0]->name->toString(), $statement->type, 'var'];
            } elseif ($statement instanceof ClassMethod) {
                [$key, $type, $tag] = [$statement->name->toString(), $statement->returnType, 'return'];
            } else {
                continue;
            }

            if ($type instanceof Node && ! $type instanceof Node\Name && ! $type instanceof Node\NullableType && (! $type instanceof Node\Identifier || ! in_array($type->toLowerString(), ['array', 'iterable'], true))) {
                continue;
            }

            $docblock = $statement->getDocComment()?->getText() ?? '';
            $templates = [...$this->docblocks->templates($class->getDocComment()?->getText() ?? ''), ...$this->docblocks->templates($docblock)];
            $elements = array_filter(
                $this->docblocks->elements($docblock)[$tag],
                static fn (string $name): bool => ! in_array($name, $templates, true),
            );

            if ($elements !== []) {
                $found[$key] = array_map(static fn (string $name): string => Analyser::qualify($name, $classFqn, $imports), $elements);
            }
        }

        return $found;
    }

    /**
     * The names of `$this->x = …` assignments to properties the class does not declare.
     *
     * @param  array<string, ClassMethod>  $methods
     * @param  array<string, Stmt\Property>  $declared
     * @param  array<string, ?string>  $promoted
     * @return list<string>
     */
    private function undeclaredProperties(array $methods, array $declared, array $promoted): array
    {
        $names = [];

        foreach ($methods as $method) {
            foreach (self::nodesIn($method->stmts ?? []) as $node) {
                if ($node instanceof Expr\Assign && $node->var instanceof Expr\PropertyFetch
                    && $node->var->var instanceof Expr\Variable && $node->var->var->name === 'this'
                    && $node->var->name instanceof Node\Identifier) {
                    $name = $node->var->name->toString();

                    if (! isset($declared[$name]) && ! array_key_exists($name, $promoted)) {
                        $names[$name] = true;
                    }
                }
            }
        }

        return array_keys($names);
    }

    /**
     * Where each undeclared property is first assigned, for the declaration that stands in for
     * it: name to the assignment.
     *
     * @return array<string, Expr\Assign>
     */
    public function firstAssignments(Stmt\ClassLike $class): array
    {
        $first = [];

        foreach ($class->stmts as $statement) {
            if (! $statement instanceof ClassMethod) {
                continue;
            }

            foreach (self::nodesIn($statement->stmts ?? []) as $node) {
                if ($node instanceof Expr\Assign && $node->var instanceof Expr\PropertyFetch
                    && $node->var->var instanceof Expr\Variable && $node->var->var->name === 'this'
                    && $node->var->name instanceof Node\Identifier) {
                    $first[$node->var->name->toString()] ??= $node;
                }
            }
        }

        return $first;
    }

    /**
     * @param  array<string, ClassMethod>  $methods
     * @return ?array{string, string}
     */
    private function assignedType(string $name, ?Stmt\Property $property, array $methods, string $classFqn): ?array
    {
        foreach ($property->props ?? [] as $item) {
            if ($item->name->toString() === $name && $item->default !== null && ! $this->isNull($item->default)) {
                return null;
            }
        }

        $types = [];

        foreach ($methods as $method) {
            $params = $this->parameterTypes($method);

            foreach (self::nodesIn($method->stmts ?? []) as $node) {
                if (($node instanceof Expr\Assign || $node instanceof Expr\AssignOp\Coalesce) && $this->isThisProperty($node->var, $name)) {
                    if ($this->isNull($node->expr)) {
                        continue;
                    }

                    $types[] = $this->valueType($node->expr, $params, [], $methods, $classFqn);
                } elseif ($node instanceof Expr\AssignRef && $this->isThisProperty($node->var, $name)) {
                    return null;
                }
            }
        }

        $type = $this->agreed($types);

        return $type === null ? null : [$type === 'static' ? $classFqn : $type, 'every assignment in the class'];
    }

    /**
     * @param  array<string, ClassMethod>  $methods
     * @param  array<string, ?string>  $propertyTypes
     * @return ?array{string, string}
     */
    private function returnedType(ClassMethod $method, array $methods, array $propertyTypes, string $classFqn): ?array
    {
        $params = $this->parameterTypes($method);
        $types = [];

        foreach (self::nodesIn($method->stmts ?? []) as $node) {
            if ($node instanceof Expr\Yield_ || $node instanceof Expr\YieldFrom) {
                return null;
            }

            if ($node instanceof Stmt\Return_) {
                if (! $node->expr instanceof Expr) {
                    return null;
                }

                if (! $this->isNull($node->expr)) {
                    $types[] = $this->valueType($node->expr, $params, $propertyTypes, $methods, $classFqn);
                }
            }
        }

        $type = $this->agreed($types);

        return $type === null ? null : [$type, 'every return in its body'];
    }

    /**
     * @param  list<Import>  $imports
     * @param  list<string>  $templates  the class's `@template` names
     * @return ?array{string, string}
     */
    private function documented(Stmt\Property|ClassMethod $node, string $tag, string $classFqn, array $imports, array $templates = []): ?array
    {
        $docblock = $node->getDocComment()?->getText() ?? '';
        $name = $this->docblocks->read($docblock)[$tag];

        if (! is_string($name)) {
            return null;
        }

        // A template is a placeholder. The class's last one is what it holds, so a method
        // returning it returns whatever the receiver is documented to hold; any other is not a
        // class at all, and qualifying it as one invented `App\Repositories\TModel`.
        $methodTemplates = $this->docblocks->templates($docblock);

        if (in_array($name, $methodTemplates, true)) {
            return null;
        }

        if (in_array($name, $templates, true)) {
            return $tag === 'return' && $name === $templates[array_key_last($templates)]
                ? [self::RECEIVER_ELEMENT, sprintf('@return %s, the class template for what it holds', $name)]
                : null;
        }

        $type = in_array(strtolower($name), ['static', '$this'], true) && $tag === 'return'
            ? 'static'
            : Analyser::qualify($name, $classFqn, $imports);

        return [$type, '@'.$tag];
    }

    /**
     * The class one value is, or null where this narrow reading can't tell.
     *
     * @param  array<string, ?string>  $params
     * @param  array<string, ?string>  $propertyTypes
     * @param  array<string, ClassMethod>  $methods
     */
    private function valueType(Expr $expr, array $params, array $propertyTypes, array $methods, string $classFqn): ?string
    {
        return match (true) {
            $expr instanceof Expr\New_ && $expr->class instanceof Node\Name => self::named($expr->class, $classFqn),
            $expr instanceof Expr\Variable && $expr->name === 'this' => 'static',
            $expr instanceof Expr\Variable && is_string($expr->name) => $params[$expr->name] ?? null,
            $expr instanceof Expr\PropertyFetch && $expr->var instanceof Expr\Variable && $expr->var->name === 'this' && $expr->name instanceof Node\Identifier => $propertyTypes[$expr->name->toString()] ?? null,
            $expr instanceof Expr\MethodCall && $expr->var instanceof Expr\Variable && $expr->var->name === 'this' && $expr->name instanceof Node\Identifier => $this->declaredReturn($methods[strtolower($expr->name->toString())] ?? null, $classFqn),
            $expr instanceof Expr\StaticCall && $expr->class instanceof Node\Name && in_array($expr->class->toLowerString(), ['self', 'static'], true) && $expr->name instanceof Node\Identifier => $this->declaredReturn($methods[strtolower($expr->name->toString())] ?? null, $classFqn),
            default => null,
        };
    }

    private function declaredReturn(?ClassMethod $method, string $classFqn): ?string
    {
        if (! $method instanceof ClassMethod || ! $method->returnType instanceof Node) {
            return null;
        }

        return self::nativeClass($method->returnType, $classFqn);
    }

    private static function nativeClass(Node $type, ?string $classFqn = null): ?string
    {
        if ($type instanceof Node\NullableType) {
            return self::nativeClass($type->type, $classFqn);
        }

        if ($type instanceof Node\Name) {
            return $classFqn === null ? Analyser::resolvedName($type) : self::named($type, $classFqn);
        }

        return $type instanceof Node\Identifier && $type->toLowerString() === 'static' ? 'static' : null;
    }

    private static function named(Node\Name $name, string $classFqn): string
    {
        return match ($name->toLowerString()) {
            'self' => $classFqn,
            'static' => 'static',
            default => Analyser::resolvedName($name),
        };
    }

    /**
     * @return array<string, ?string>
     */
    private function parameterTypes(ClassMethod $method): array
    {
        $types = [];

        foreach ($method->params as $param) {
            if ($param->var instanceof Expr\Variable && is_string($param->var->name)) {
                $types[$param->var->name] = $param->type === null || $param->variadic ? null : self::nativeClass($param->type);
            }
        }

        return $types;
    }

    /**
     * @param  list<?string>  $types
     */
    private function agreed(array $types): ?string
    {
        if ($types === [] || in_array(null, $types, true)) {
            return null;
        }

        $distinct = array_unique(array_map(strtolower(...), $types));

        return count($distinct) === 1 ? $types[0] : null;
    }

    private function isThisProperty(Expr $expr, string $name): bool
    {
        return $expr instanceof Expr\PropertyFetch
            && $expr->var instanceof Expr\Variable
            && $expr->var->name === 'this'
            && $expr->name instanceof Node\Identifier
            && $expr->name->toString() === $name;
    }

    private function isNull(Expr $expr): bool
    {
        return $expr instanceof Expr\ConstFetch && $expr->name->toLowerString() === 'null';
    }

    /**
     * Every node in a body, without entering a closure, arrow function or class declared in it:
     * their `return` and `$this` belong to something else.
     *
     * @param  array<Node>  $nodes
     * @return \Generator<Node>
     */
    private static function nodesIn(array $nodes): \Generator
    {
        foreach ($nodes as $node) {
            yield $node;

            if ($node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction || $node instanceof Stmt\ClassLike || $node instanceof Stmt\Function_) {
                continue;
            }

            foreach ($node->getSubNodeNames() as $sub) {
                $child = $node->{$sub};

                if ($child instanceof Node) {
                    yield from self::nodesIn([$child]);
                } elseif (is_array($child)) {
                    yield from self::nodesIn(array_filter($child, static fn (mixed $c): bool => $c instanceof Node));
                }
            }
        }
    }
}

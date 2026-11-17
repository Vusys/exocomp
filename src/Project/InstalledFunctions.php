<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Project;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPStan\PhpDocParser\Ast\PhpDoc\ReturnTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\ConditionalTypeForParameterNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use Throwable;
use Vusys\Exocomp\Inference\ResolvedType;

/**
 * The return types of the functions an installed package declares: `request()`, `auth()`,
 * `now()`, the global helpers a Laravel application calls on every other line.
 *
 * They are not in the index, which holds the project's own code, so `request()->user()` used to
 * stop at "the return type of request() was not read". Composer lists the files that declare
 * them (`autoload_files.php`), and those are read the first time a function is not found in the
 * index, then kept for the run.
 *
 * Two forms of answer, and nothing else:
 *
 * - a native return type naming one class is the answer, as it would be for the project's own
 *   functions;
 * - a docblock `@return ($param is null ? A : B)` — how the framework writes "with no argument
 *   this returns the request" — is evaluated against the call: an argument left out, or passed
 *   as a literal `null`, takes the first branch; any other argument the second. Read from a
 *   docblock, so `inferred`.
 *
 * A union, a generic, or a condition on anything but a parameter being null is not evaluated.
 */
final class InstalledFunctions
{
    /** @var array<string, Node\Stmt\Function_>|null functions by folded name, once the files are read */
    private ?array $functions = null;

    /** @var array<string, array<string, string>> per function, the `use` map of the file declaring it */
    private array $imports = [];

    private function __construct(private readonly Project $project) {}

    public static function forProject(Project $project): self
    {
        return new self($project);
    }

    /**
     * What a call to the function `$fqn` returns: null when no installed package declares it,
     * and an unknown type, with the reason, when one does but this call's result is not a class.
     */
    public function returnType(string $fqn, Expr\FuncCall $call): ?ResolvedType
    {
        $key = strtolower(ltrim($fqn, '\\'));
        $function = $this->functions()[$key] ?? null;

        if (! $function instanceof Node\Stmt\Function_) {
            return null;
        }

        $native = $this->singleClass($function->returnType);

        if ($native !== null) {
            return ResolvedType::resolvedBy($native, sprintf('declared by %s(), in an installed package', $function->name->toString()));
        }

        $docblock = $function->getDocComment()?->getText();
        $returns = $docblock === null ? null : $this->docReturn($docblock);
        $class = $returns instanceof TypeNode ? $this->evaluate($returns, $function, $call, $this->imports[$key] ?? []) : null;

        return $class === null
            ? ResolvedType::unknown(sprintf('%s(), in an installed package, does not return a class for this call', $function->name->toString()))
            : ResolvedType::inferred($class, sprintf('the @return of %s(), in an installed package, for this call', $function->name->toString()));
    }

    /**
     * @return array<string, Node\Stmt\Function_>
     */
    private function functions(): array
    {
        if ($this->functions !== null) {
            return $this->functions;
        }

        $this->functions = [];
        $listing = $this->project->path('vendor/composer/autoload_files.php');

        if (! is_file($listing)) {
            return $this->functions;
        }

        try {
            $files = (static fn (string $path): mixed => require $path)($listing);
        } catch (Throwable) {
            return $this->functions;
        }

        if (! is_array($files)) {
            return $this->functions;
        }

        $parser = new ParserFactory()->createForHostVersion();

        foreach ($files as $file) {
            if (! is_string($file) || ! is_file($file)) {
                continue;
            }

            try {
                $source = (string) file_get_contents($file);

                // Most autoloaded files are not helpers at all; only one that declares a function
                // is worth parsing.
                if (! str_contains($source, 'function ')) {
                    continue;
                }

                $statements = $parser->parse($source) ?? [];
            } catch (Throwable) {
                continue;
            }

            $traverser = new NodeTraverser(new NameResolver);
            $statements = $traverser->traverse($statements);
            $uses = $this->useMap($statements);

            foreach (new NodeFinder()->findInstanceOf($statements, Node\Stmt\Function_::class) as $function) {
                $name = strtolower($function->namespacedName?->toString() ?? $function->name->toString());
                $this->functions[$name] ??= $function;
                $this->imports[$name] ??= $uses;
            }
        }

        return $this->functions;
    }

    /**
     * @param  array<Node>  $statements
     * @return array<string, string> alias (folded) to fully-qualified name
     */
    private function useMap(array $statements): array
    {
        $uses = [];

        foreach (new NodeFinder()->findInstanceOf($statements, Node\Stmt\Use_::class) as $use) {
            foreach ($use->uses as $item) {
                $uses[strtolower($item->getAlias()->toString())] = $item->name->toString();
            }
        }

        return $uses;
    }

    private function singleClass(?Node $type): ?string
    {
        if ($type instanceof Node\NullableType) {
            $type = $type->type;
        }

        return $type instanceof Node\Name && ! in_array(strtolower($type->toString()), ['self', 'static', 'parent'], true)
            ? $type->toString()
            : null;
    }

    private function docReturn(string $docblock): ?TypeNode
    {
        $config = new ParserConfig([]);
        $constants = new ConstExprParser($config);
        $parser = new PhpDocParser($config, new TypeParser($config, $constants), $constants);

        try {
            $node = $parser->parse(new TokenIterator(new Lexer($config)->tokenize($docblock)));
        } catch (Throwable) {
            return null;
        }

        foreach ($node->getTags() as $tag) {
            if ($tag->value instanceof ReturnTagValueNode) {
                return $tag->value->type;
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $uses
     */
    private function evaluate(TypeNode $type, Node\Stmt\Function_ $function, Expr\FuncCall $call, array $uses): ?string
    {
        if ($type instanceof NullableTypeNode) {
            $type = $type->type;
        }

        if ($type instanceof IdentifierTypeNode) {
            return $this->qualify($type->name, $uses);
        }

        if (! $type instanceof ConditionalTypeForParameterNode || ! $type->targetType instanceof IdentifierTypeNode
            || strtolower($type->targetType->name) !== 'null') {
            return null;
        }

        $isNull = $this->argumentIsNull($function, $call, ltrim($type->parameterName, '$'));

        if ($isNull === null) {
            return null;
        }

        // `is null` matches when the argument is null; `is not null` the other way round.
        return $this->evaluate($isNull !== $type->negated ? $type->if : $type->else, $function, $call, $uses);
    }

    /**
     * Whether the call passes null for the named parameter: true when it is left out or written
     * as a literal `null`, false when something else is written, null when that cannot be told
     * (a spread argument, or a parameter the function does not declare).
     */
    private function argumentIsNull(Node\Stmt\Function_ $function, Expr\FuncCall $call, string $parameter): ?bool
    {
        $position = null;

        foreach ($function->params as $index => $param) {
            if ($param->var instanceof Expr\Variable && $param->var->name === $parameter) {
                $position = $index;
            }
        }

        if ($position === null) {
            return null;
        }

        $argument = null;

        foreach ($call->args as $index => $arg) {
            if (! $arg instanceof Node\Arg || $arg->unpack) {
                return null;
            }

            if ($arg->name instanceof Node\Identifier ? $arg->name->toString() === $parameter : $index === $position) {
                $argument = $arg->value;
            }
        }

        return ! $argument instanceof Expr
            || ($argument instanceof Expr\ConstFetch && strtolower($argument->name->toString()) === 'null');
    }

    /**
     * @param  array<string, string>  $uses
     */
    private function qualify(string $name, array $uses): ?string
    {
        if (in_array(strtolower($name), ['mixed', 'void', 'null', 'array', 'string', 'int', 'float', 'bool', 'object', 'callable', 'iterable', 'never', 'true', 'false', 'self', 'static'], true)) {
            return null;
        }

        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }

        $first = strtolower(explode('\\', $name)[0]);

        if (isset($uses[$first])) {
            $rest = substr($name, strlen($first));

            return $uses[$first].$rest;
        }

        return $name;
    }
}

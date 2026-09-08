<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Analysis;

use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use Vusys\Exocomp\Render\SignatureRenderer;
use Vusys\Exocomp\Source\SourceFile;
use Vusys\Exocomp\Symbol\SymbolKind;

/**
 * Walks a resolved AST and records every named declaration with its exact span.
 *
 * It walks statements manually rather than with a `NodeVisitor` because the output is a tree —
 * a class owns its members — and a flat visitor callback would have to reconstruct that
 * ownership afterwards from positions, which is precisely the guesswork this tool exists to
 * remove.
 *
 * It descends through namespaces, `declare` blocks and conditionals, because several classes
 * in one file and a class declared inside an `if` are both legal PHP and both appear in real
 * codebases. It does not descend into function bodies: anonymous classes have no name to
 * address them by, so there is nothing there this tool can offer.
 */
final class DeclarationCollector
{
    /** @var list<Declaration> */
    private array $declarations = [];

    /** @var list<Import> */
    private array $imports = [];

    /** @var list<string> the namespaces declared in this file, "" for the global one */
    private array $namespaces = [];

    private readonly SignatureRenderer $signatures;

    public function __construct(private readonly SourceFile $file)
    {
        $this->signatures = new SignatureRenderer($file);
    }

    /**
     * The traverser hands back `Node[]` rather than `Stmt[]`, so non-statement nodes are
     * skipped here rather than assumed away.
     *
     * @param  array<int, Node>  $nodes
     */
    public function collect(array $nodes): void
    {
        foreach ($nodes as $node) {
            if ($node instanceof Stmt) {
                $this->collectStatement($node);
            }
        }
    }

    /**
     * @return list<Declaration>
     */
    public function declarations(): array
    {
        return $this->declarations;
    }

    /**
     * @return list<Import>
     */
    public function imports(): array
    {
        return $this->imports;
    }

    /**
     * @return list<string>
     */
    public function namespaces(): array
    {
        return $this->namespaces;
    }

    private function collectStatement(Stmt $statement): void
    {
        match (true) {
            $statement instanceof Stmt\Namespace_ => $this->collectNamespace($statement),
            $statement instanceof Stmt\Declare_ => $this->collect($statement->stmts ?? []),
            $statement instanceof Stmt\Block => $this->collect($statement->stmts),
            $statement instanceof Stmt\Use_ => $this->collectUse($statement),
            $statement instanceof Stmt\GroupUse => $this->collectGroupUse($statement),
            $statement instanceof Stmt\ClassLike => $this->collectClassLike($statement),
            $statement instanceof Stmt\Function_ => $this->collectFunction($statement),
            $statement instanceof Stmt\If_ => $this->collectConditional($statement),
            $statement instanceof Stmt\TryCatch => $this->collect($statement->stmts),
            default => null,
        };
    }

    private function collectNamespace(Stmt\Namespace_ $statement): void
    {
        $this->namespaces[] = $statement->name?->toString() ?? '';

        $this->collect($statement->stmts);
    }

    private function collectConditional(Stmt\If_ $statement): void
    {
        $this->collect($statement->stmts);

        foreach ($statement->elseifs as $elseif) {
            $this->collect($elseif->stmts);
        }

        if ($statement->else instanceof Stmt\Else_) {
            $this->collect($statement->else->stmts);
        }
    }

    private function collectUse(Stmt\Use_ $statement): void
    {
        foreach ($statement->uses as $use) {
            $this->imports[] = $this->importFrom($use, $use->name->toString(), $statement->type);
        }
    }

    private function collectGroupUse(Stmt\GroupUse $statement): void
    {
        foreach ($statement->uses as $use) {
            // A group `use` may set the kind per item (`use A\{function b, const C};`) or
            // inherit it from the group. TYPE_UNKNOWN on the item means "inherit".
            $type = $use->type === Stmt\Use_::TYPE_UNKNOWN ? $statement->type : $use->type;

            $this->imports[] = $this->importFrom(
                $use,
                $statement->prefix->toString().'\\'.$use->name->toString(),
                $type,
            );
        }
    }

    private function importFrom(Node\UseItem $use, string $fqn, int $type): Import
    {
        return new Import(
            fqn: $fqn,
            alias: $use->getAlias()->toString(),
            kind: match ($type) {
                Stmt\Use_::TYPE_FUNCTION => ImportKind::FunctionName,
                Stmt\Use_::TYPE_CONSTANT => ImportKind::ConstantName,
                default => ImportKind::ClassLike,
            },
            line: $use->getStartLine(),
            column: $this->file->columnAt($use->getStartFilePos()),
            isAliased: $use->alias instanceof Node\Identifier,
        );
    }

    private function collectClassLike(Stmt\ClassLike $node): void
    {
        $name = $node->name?->toString();

        if ($name === null) {
            return;
        }

        $fqn = $node->namespacedName?->toString() ?? $name;
        $members = [];
        $traits = [];

        foreach ($node->stmts as $statement) {
            match (true) {
                $statement instanceof Stmt\ClassMethod => $members = [...$members, ...$this->methodMembers($statement, $fqn)],
                $statement instanceof Stmt\Property => $members = [...$members, ...$this->propertyMembers($statement, $fqn)],
                $statement instanceof Stmt\ClassConst => $members = [...$members, ...$this->constantMembers($statement, $fqn)],
                $statement instanceof Stmt\EnumCase => $members[] = $this->enumCaseMember($statement, $fqn),
                $statement instanceof Stmt\TraitUse => $traits = [...$traits, ...$this->resolvedNames($statement->traits)],
                default => null,
            };
        }

        usort($members, static fn (Declaration $a, Declaration $b): int => $a->startOffset <=> $b->startOffset);

        $signature = $this->signatures->classLike($node);

        $this->declarations[] = new Declaration(
            kind: $this->classLikeKind($node),
            name: $name,
            fqn: $fqn,
            startLine: $node->getStartLine(),
            endLine: $node->getEndLine(),
            startColumn: $this->file->columnAt($node->getStartFilePos()),
            startOffset: $node->getStartFilePos(),
            endOffset: $node->getEndFilePos(),
            spanStartLine: $this->spanStartLine($node),
            spanStartOffset: $this->spanStartOffset($node),
            signature: $signature,
            fullSignature: $signature,
            modifiers: $this->classLikeModifiers($node),
            members: $members,
            extends: $this->extendsNames($node),
            implements: $this->implementsNames($node),
            traits: $traits,
            hasBody: true,
        );
    }

    private function collectFunction(Stmt\Function_ $node): void
    {
        $this->declarations[] = new Declaration(
            kind: SymbolKind::FunctionDecl,
            name: $node->name->toString(),
            fqn: $node->namespacedName?->toString() ?? $node->name->toString(),
            startLine: $node->getStartLine(),
            endLine: $node->getEndLine(),
            startColumn: $this->file->columnAt($node->getStartFilePos()),
            startOffset: $node->getStartFilePos(),
            endOffset: $node->getEndFilePos(),
            spanStartLine: $this->spanStartLine($node),
            spanStartOffset: $this->spanStartOffset($node),
            signature: $this->signatures->function($node, false),
            fullSignature: $this->signatures->function($node, true),
            hasBody: true,
        );
    }

    /**
     * @return list<Declaration>
     */
    private function methodMembers(Stmt\ClassMethod $node, string $ownerFqn): array
    {
        $method = new Declaration(
            kind: SymbolKind::Method,
            name: $node->name->toString(),
            fqn: $ownerFqn.'::'.$node->name->toString(),
            startLine: $node->getStartLine(),
            endLine: $node->getEndLine(),
            startColumn: $this->file->columnAt($node->getStartFilePos()),
            startOffset: $node->getStartFilePos(),
            endOffset: $node->getEndFilePos(),
            spanStartLine: $this->spanStartLine($node),
            spanStartOffset: $this->spanStartOffset($node),
            signature: $this->signatures->method($node, false),
            fullSignature: $this->signatures->method($node, true),
            modifiers: SignatureRenderer::modifiers($node->flags),
            hasBody: $node->stmts !== null,
            typeFqn: self::classLikeTypeOf($node->returnType),
        );

        return [$method, ...$this->promotedMembers($node, $ownerFqn)];
    }

    /**
     * Constructor-promoted parameters are properties. A reader asking `members` for a class's
     * API must see them, and grep for `private .* \$` never will, because they are written in
     * the signature rather than in the class body.
     *
     * @return list<Declaration>
     */
    private function promotedMembers(Stmt\ClassMethod $node, string $ownerFqn): array
    {
        $promoted = [];

        foreach ($node->params as $param) {
            if ($param->flags === 0) {
                continue;
            }

            $var = $param->var;

            if (! $var instanceof Node\Expr\Variable || ! is_string($var->name)) {
                continue;
            }

            $promoted[] = new Declaration(
                kind: SymbolKind::Property,
                name: $var->name,
                fqn: $ownerFqn.'::$'.$var->name,
                startLine: $param->getStartLine(),
                endLine: $param->getEndLine(),
                startColumn: $this->file->columnAt($param->getStartFilePos()),
                startOffset: $param->getStartFilePos(),
                endOffset: $param->getEndFilePos(),
                spanStartLine: $param->getStartLine(),
                spanStartOffset: $param->getStartFilePos(),
                signature: $this->signatures->promotedProperty($param, false),
                fullSignature: $this->signatures->promotedProperty($param, true),
                modifiers: SignatureRenderer::modifiers($param->flags),
                isPromoted: true,
                typeFqn: self::classLikeTypeOf($param->type),
            );
        }

        return $promoted;
    }

    /**
     * @return list<Declaration>
     */
    private function propertyMembers(Stmt\Property $node, string $ownerFqn): array
    {
        $properties = [];

        foreach ($node->props as $item) {
            $name = $item->name->toString();

            // `public int $a = 1, $b = 2;` declares two properties from one statement. The
            // span for each is the whole statement, because that is the text that declares it.
            $properties[] = new Declaration(
                kind: SymbolKind::Property,
                name: $name,
                fqn: $ownerFqn.'::$'.$name,
                startLine: $node->getStartLine(),
                endLine: $node->getEndLine(),
                startColumn: $this->file->columnAt($node->getStartFilePos()),
                startOffset: $node->getStartFilePos(),
                endOffset: $node->getEndFilePos(),
                spanStartLine: $this->spanStartLine($node),
                spanStartOffset: $this->spanStartOffset($node),
                signature: $this->signatures->property($node, $item, false),
                fullSignature: $this->signatures->property($node, $item, true),
                modifiers: SignatureRenderer::modifiers($node->flags),
                hasBody: $node->hooks !== [],
                typeFqn: self::classLikeTypeOf($node->type),
            );
        }

        return $properties;
    }

    /**
     * @return list<Declaration>
     */
    private function constantMembers(Stmt\ClassConst $node, string $ownerFqn): array
    {
        $constants = [];

        foreach ($node->consts as $item) {
            $name = $item->name->toString();

            $constants[] = new Declaration(
                kind: SymbolKind::Constant,
                name: $name,
                fqn: $ownerFqn.'::'.$name,
                startLine: $node->getStartLine(),
                endLine: $node->getEndLine(),
                startColumn: $this->file->columnAt($node->getStartFilePos()),
                startOffset: $node->getStartFilePos(),
                endOffset: $node->getEndFilePos(),
                spanStartLine: $this->spanStartLine($node),
                spanStartOffset: $this->spanStartOffset($node),
                signature: $this->signatures->constant($node, $item),
                fullSignature: $this->signatures->constant($node, $item),
                modifiers: SignatureRenderer::modifiers($node->flags),
            );
        }

        return $constants;
    }

    private function enumCaseMember(Stmt\EnumCase $node, string $ownerFqn): Declaration
    {
        $name = $node->name->toString();

        return new Declaration(
            kind: SymbolKind::EnumCase,
            name: $name,
            fqn: $ownerFqn.'::'.$name,
            startLine: $node->getStartLine(),
            endLine: $node->getEndLine(),
            startColumn: $this->file->columnAt($node->getStartFilePos()),
            startOffset: $node->getStartFilePos(),
            endOffset: $node->getEndFilePos(),
            spanStartLine: $this->spanStartLine($node),
            spanStartOffset: $this->spanStartOffset($node),
            signature: $this->signatures->enumCase($node),
            fullSignature: $this->signatures->enumCase($node),
        );
    }

    /**
     * A docblock is part of the element it documents, so `show` includes it. Attributes are
     * already inside the node's own span.
     */
    private function spanStartOffset(Node $node): int
    {
        $doc = $node->getDocComment();

        return $doc instanceof Doc ? $doc->getStartFilePos() : $node->getStartFilePos();
    }

    private function spanStartLine(Node $node): int
    {
        $doc = $node->getDocComment();

        return $doc instanceof Doc ? $doc->getStartLine() : $node->getStartLine();
    }

    /**
     * @param  array<int, Node\Name>  $names
     * @return list<string>
     */
    private function resolvedNames(array $names): array
    {
        return array_map(Analyser::resolvedName(...), array_values($names));
    }

    /**
     * @return list<string>
     */
    private function extendsNames(Stmt\ClassLike $node): array
    {
        if ($node instanceof Stmt\Class_) {
            return $node->extends instanceof Node\Name ? [Analyser::resolvedName($node->extends)] : [];
        }

        if ($node instanceof Stmt\Interface_) {
            return $this->resolvedNames($node->extends);
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function implementsNames(Stmt\ClassLike $node): array
    {
        return match (true) {
            $node instanceof Stmt\Class_ => $this->resolvedNames($node->implements),
            $node instanceof Stmt\Enum_ => $this->resolvedNames($node->implements),
            default => [],
        };
    }

    /**
     * The resolved name of a type, when it is a single class-like one.
     *
     * A nullable class type still resolves — `?Repository` is a `Repository` wherever it is not
     * null, and a receiver that might be null is still a receiver of that class. Unions and
     * intersections deliberately do not: picking one member would be a guess, and not guessing
     * is this engine's whole claim.
     */
    private static function classLikeTypeOf(?Node $type): ?string
    {
        if ($type instanceof Node\NullableType) {
            return self::classLikeTypeOf($type->type);
        }

        return $type instanceof Node\Name ? Analyser::resolvedName($type) : null;
    }

    private function classLikeKind(Stmt\ClassLike $node): SymbolKind
    {
        return match (true) {
            $node instanceof Stmt\Interface_ => SymbolKind::InterfaceType,
            $node instanceof Stmt\Trait_ => SymbolKind::TraitType,
            $node instanceof Stmt\Enum_ => SymbolKind::EnumType,
            default => SymbolKind::ClassType,
        };
    }

    /**
     * @return list<string>
     */
    private function classLikeModifiers(Stmt\ClassLike $node): array
    {
        if (! $node instanceof Stmt\Class_) {
            return [];
        }

        $modifiers = [];

        if ($node->isAbstract()) {
            $modifiers[] = 'abstract';
        }

        if ($node->isFinal()) {
            $modifiers[] = 'final';
        }

        if ($node->isReadonly()) {
            $modifiers[] = 'readonly';
        }

        return $modifiers;
    }
}

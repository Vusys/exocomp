<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Render;

use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Const_;
use PhpParser\Node\Param;
use PhpParser\Node\PropertyItem;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\EnumCase;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Trait_;
use Vusys\Exocomp\Source\SourceFile;

/**
 * Turns declaration nodes into the one-line signatures the text output is built from.
 *
 * Two registers, and the difference is deliberate. Dense signatures drop parameter names and
 * shorten types (`handle(Order): void`) because `outline`'s entire value proposition is being
 * a few percent of the file's tokens. Full signatures keep the source's own words, because
 * `show --without-bodies` should read as the PHP it came from.
 */
final readonly class SignatureRenderer
{
    private const int VALUE_BUDGET = 60;

    public function __construct(private SourceFile $file) {}

    public function classLike(ClassLike $node): string
    {
        $keyword = match (true) {
            $node instanceof Interface_ => 'interface',
            $node instanceof Trait_ => 'trait',
            $node instanceof Enum_ => 'enum',
            default => 'class',
        };

        $parts = $this->classModifiers($node);
        $parts[] = $keyword;
        $parts[] = $node->name?->toString() ?? '(anonymous)';

        if ($node instanceof Enum_ && $node->scalarType instanceof Node) {
            $parts[count($parts) - 1] .= ': '.(TypeRenderer::short($node->scalarType) ?? '');
        }

        $extends = $this->extendedNames($node);

        if ($extends !== []) {
            $parts[] = 'extends '.implode(', ', $extends);
        }

        $implements = $this->implementedNames($node);

        if ($implements !== []) {
            $parts[] = 'implements '.implode(', ', $implements);
        }

        return implode(' ', $parts);
    }

    public function method(ClassMethod $node, bool $withParamNames): string
    {
        $parts = self::modifiers($node->flags);
        $parts[] = 'function';
        $parts[] = $node->name->toString().$this->params($node->params, $withParamNames);

        $signature = implode(' ', $parts);

        return $signature.$this->returnType($node->returnType, $withParamNames);
    }

    public function function(Function_ $node, bool $withParamNames): string
    {
        $name = $node->name->toString().$this->params($node->params, $withParamNames);

        return 'function '.$name.$this->returnType($node->returnType, $withParamNames);
    }

    public function property(Property $node, PropertyItem $item, bool $withValue): string
    {
        $parts = self::modifiers($node->flags);
        $type = $withValue
            ? $this->asWritten($node->type)
            : TypeRenderer::short($node->type);

        if ($type !== null && $type !== '') {
            $parts[] = $type;
        }

        $parts[] = '$'.$item->name->toString();

        if ($item->default instanceof Node && ($withValue || $this->isSimpleValue($item->default))) {
            $parts[] = '= '.$this->value($item->default);
        }

        $hooks = $this->hookList($node->hooks);

        if ($hooks !== null) {
            $parts[] = $hooks;
        }

        return implode(' ', $parts);
    }

    public function promotedProperty(Param $param, bool $withValue): string
    {
        $parts = self::modifiers($param->flags);
        $type = $withValue ? $this->asWritten($param->type) : TypeRenderer::short($param->type);

        if ($type !== null && $type !== '') {
            $parts[] = $type;
        }

        $parts[] = $this->paramName($param);

        if ($param->default instanceof Node && ($withValue || $this->isSimpleValue($param->default))) {
            $parts[] = '= '.$this->value($param->default);
        }

        $hooks = $this->hookList($param->hooks);

        if ($hooks !== null) {
            $parts[] = $hooks;
        }

        return implode(' ', $parts);
    }

    public function constant(ClassConst $node, Const_ $item): string
    {
        $parts = self::modifiers($node->flags);
        $parts[] = 'const';
        $type = TypeRenderer::short($node->type);

        if ($type !== null) {
            $parts[] = $type;
        }

        $parts[] = $item->name->toString();
        $parts[] = '= '.$this->value($item->value);

        return implode(' ', $parts);
    }

    public function enumCase(EnumCase $node): string
    {
        $signature = 'case '.$node->name->toString();

        if (! $node->expr instanceof Node) {
            return $signature;
        }

        return $signature.' = '.$this->value($node->expr);
    }

    /**
     * @return list<string>
     */
    public static function modifiers(int $flags): array
    {
        $modifiers = [];

        if (($flags & Modifiers::ABSTRACT) !== 0) {
            $modifiers[] = 'abstract';
        }

        if (($flags & Modifiers::FINAL) !== 0) {
            $modifiers[] = 'final';
        }

        $modifiers[] = match (true) {
            ($flags & Modifiers::PRIVATE) !== 0 => 'private',
            ($flags & Modifiers::PROTECTED) !== 0 => 'protected',
            default => 'public',
        };

        // Asymmetric visibility (8.4). Rendering it is not cosmetic: `public private(set)` and
        // `public` are different APIs, and a tool that flattened them would be reporting a
        // writable property as writable when it is not.
        $setVisibility = match (true) {
            ($flags & Modifiers::PRIVATE_SET) !== 0 => 'private(set)',
            ($flags & Modifiers::PROTECTED_SET) !== 0 => 'protected(set)',
            ($flags & Modifiers::PUBLIC_SET) !== 0 => 'public(set)',
            default => null,
        };

        if ($setVisibility !== null) {
            $modifiers[] = $setVisibility;
        }

        if (($flags & Modifiers::STATIC) !== 0) {
            $modifiers[] = 'static';
        }

        if (($flags & Modifiers::READONLY) !== 0) {
            $modifiers[] = 'readonly';
        }

        return $modifiers;
    }

    /**
     * Whether a default value is worth carrying in a dense one-line record.
     *
     * For a great many properties the value *is* the answer — `$table`, `$connection`, a
     * lookback window, a feature flag — and a signature that shows the type but hides the
     * value sends the reader back to the file for one token's worth of information. Scalars,
     * `true`/`false`/`null` and constant references are short and settle the question;
     * arrays and expressions are neither, and belong to `show`.
     */
    private function isSimpleValue(Node $node): bool
    {
        return $node instanceof Node\Scalar\String_
            || $node instanceof Node\Scalar\Int_
            || $node instanceof Node\Scalar\Float_
            || $node instanceof Node\Expr\ConstFetch
            || $node instanceof Node\Expr\ClassConstFetch;
    }

    /**
     * @param  array<int, Node\PropertyHook>  $hooks
     */
    private function hookList(array $hooks): ?string
    {
        if ($hooks === []) {
            return null;
        }

        $names = array_map(static fn (Node\PropertyHook $hook): string => $hook->name->toString().';', $hooks);

        return '{ '.implode(' ', $names).' }';
    }

    /**
     * @param  array<int, Param>  $params
     */
    private function params(array $params, bool $withNames): string
    {
        $rendered = array_map(
            fn (Param $param): string => $withNames ? $this->paramAsWritten($param) : $this->denseParam($param),
            $params,
        );

        return '('.implode(', ', $rendered).')';
    }

    /**
     * Dense parameters carry the type where there is one and the variable name where there is
     * not, so an untyped parameter is visible as untyped rather than silently rendered as
     * `mixed` — which would be an inference the tool has not made.
     */
    private function denseParam(Param $param): string
    {
        $rendered = TypeRenderer::short($param->type) ?? $this->paramName($param);

        if ($param->variadic) {
            $rendered = '...'.$rendered;
        }

        if ($param->byRef) {
            $rendered = '&'.$rendered;
        }

        return $param->default instanceof Node ? $rendered.'=' : $rendered;
    }

    private function paramAsWritten(Param $param): string
    {
        $text = $this->rawSlice($param);

        return $text ?? $this->denseParam($param);
    }

    private function paramName(Param $param): string
    {
        $var = $param->var;

        if ($var instanceof Node\Expr\Variable && is_string($var->name)) {
            return '$'.$var->name;
        }

        return '$…';
    }

    private function returnType(?Node $type, bool $asWritten): string
    {
        $rendered = $asWritten ? $this->asWritten($type) : TypeRenderer::short($type);

        return $rendered === null || $rendered === '' ? '' : ': '.$rendered;
    }

    private function asWritten(?Node $node): ?string
    {
        if (! $node instanceof Node) {
            return null;
        }

        return $this->rawSlice($node) ?? TypeRenderer::short($node);
    }

    /**
     * Constant and default values are rendered from the original bytes, then collapsed onto
     * one line and capped. The cap is visible (`…`) because a value that silently lost its
     * tail would read as the whole value.
     *
     * The cut is on a character boundary, not a byte one. `substr()` here would split a
     * multi-byte character in half and leave a dangling continuation byte — which is not a
     * cosmetic problem: the result is no longer valid UTF-8, and `--json` cannot encode it.
     * Found by rendering every declaration in a 3.9M-line vendor tree, where a handful of
     * localisation fixtures have long non-Latin string defaults.
     */
    private function value(Node $node): string
    {
        $text = $this->rawSlice($node) ?? '?';
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        if (strlen($text) <= self::VALUE_BUDGET) {
            return $text;
        }

        return mb_strcut($text, 0, self::VALUE_BUDGET, 'UTF-8').'…';
    }

    private function rawSlice(Node $node): ?string
    {
        $start = $node->getStartFilePos();
        $end = $node->getEndFilePos();

        if ($start < 0 || $end < $start) {
            return null;
        }

        return $this->file->slice($start, $end);
    }

    /**
     * @return list<string>
     */
    private function classModifiers(ClassLike $node): array
    {
        if (! $node instanceof Node\Stmt\Class_) {
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

    /**
     * @return list<string>
     */
    private function extendedNames(ClassLike $node): array
    {
        if ($node instanceof Node\Stmt\Class_) {
            return $node->extends instanceof Node\Name ? [$node->extends->getLast()] : [];
        }

        if ($node instanceof Interface_) {
            return array_values(array_map(static fn (Node\Name $name): string => $name->getLast(), $node->extends));
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function implementedNames(ClassLike $node): array
    {
        $names = match (true) {
            $node instanceof Node\Stmt\Class_ => $node->implements,
            $node instanceof Enum_ => $node->implements,
            default => [],
        };

        return array_values(array_map(static fn (Node\Name $name): string => $name->getLast(), $names));
    }
}

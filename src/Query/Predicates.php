<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Query;

use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\Node\ArrayItem;
use Vusys\Exocomp\Analysis\Analyser;
use Vusys\Exocomp\Index\SymbolGraph;
use Vusys\Exocomp\Inference\ResolvedType;
use Vusys\Exocomp\Render\TypeRenderer;

/**
 * The filters that turn a pattern match into an answer.
 *
 * Each of these was a piped `grep`/`grep -v` in the measured history — 521 positive
 * post-filters and 315 negative ones — applied to text that had already thrown away the
 * structure needed to apply them correctly. Every predicate here is syntactic: no types are
 * inferred, so nothing is guessed, and what cannot be judged is not filtered on at all.
 */
final readonly class Predicates
{
    /**
     * @param  list<string>  $in  namespace globs the match must sit inside
     * @param  list<string>  $notIn  namespace globs it must not
     * @param  list<string>  $annotated  docblock tags the enclosing declaration must carry
     * @param  list<string>  $attributes  attribute names it must carry
     * @param  list<int>  $literalArguments  argument positions that must be a literal
     * @param  list<int>  $nonLiteralArguments  argument positions that must not be
     * @param  list<string>  $usesTrait  traits the enclosing class must use
     * @param  list<string>  $extends  classes it must extend
     * @param  list<string>  $implements  interfaces it must implement
     * @param  list<string>  $receiverTypes  the matched call's receiver is exactly one of these
     * @param  list<string>  $receiverImplements  its receiver implements one of these
     * @param  list<string>  $receiverExtends  its receiver extends one of these
     */
    public function __construct(
        public array $in = [],
        public array $notIn = [],
        public array $annotated = [],
        public array $attributes = [],
        public array $literalArguments = [],
        public array $nonLiteralArguments = [],
        public ?int $paramCount = null,
        public ?string $visibility = null,
        public ?string $returns = null,
        public array $usesTrait = [],
        public array $extends = [],
        public array $implements = [],
        public array $receiverTypes = [],
        public array $receiverImplements = [],
        public array $receiverExtends = [],
    ) {}

    public function accepts(QueryMatch $match): bool
    {
        return $this->withinNamespace($match)
            && $this->carriesAnnotations($match)
            && $this->carriesAttributes($match)
            && $this->argumentsAreAsRequired($match)
            && $this->signatureMatches($match)
            && $this->classMatches($match)
            && $this->receiverMatches($match);
    }

    /**
     * Whether any predicate here needs a receiver's type worked out. Resolving one walks the
     * hierarchy, so a query that never asks should never pay for it.
     */
    public function needsReceiver(): bool
    {
        return $this->receiverTypes !== [] || $this->receiverImplements !== [] || $this->receiverExtends !== [];
    }

    /**
     * The predicates that need inference, and therefore the only ones that can fail to have an
     * answer. A receiver whose type could not be established does not match — silently letting
     * it through would turn a scoped audit into an unscoped one without saying so.
     */
    private function receiverMatches(QueryMatch $match): bool
    {
        if (! $this->needsReceiver()) {
            return true;
        }

        $type = $match->receiverType;

        if (! $type instanceof ResolvedType || ! $type->isKnown() || ! $match->graph instanceof SymbolGraph) {
            return false;
        }

        $fqn = (string) $type->fqn;

        foreach ($this->receiverTypes as $wanted) {
            if (strcasecmp(trim($fqn, '\\'), trim($wanted, '\\')) === 0) {
                return true;
            }
        }

        foreach ([...$this->receiverImplements, ...$this->receiverExtends] as $wanted) {
            foreach (array_keys($match->graph->ancestorsOf($fqn)) as $ancestor) {
                if (strcasecmp(trim($ancestor, '\\'), trim($wanted, '\\')) === 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The literal head of the `--in` globs, for skipping files that cannot contain a match.
     *
     * @return list<string>
     */
    public function namespacePrefixes(): array
    {
        return array_values(array_filter(array_map(NamespaceGlob::literalPrefix(...), $this->in)));
    }

    private function withinNamespace(QueryMatch $match): bool
    {
        $subject = $match->classFqn ?? ($match->namespace === '' ? '' : $match->namespace.'\\');

        foreach ($this->notIn as $glob) {
            if (NamespaceGlob::matches($glob, $subject)) {
                return false;
            }
        }

        if ($this->in === []) {
            return true;
        }

        return array_any($this->in, fn (string $glob): bool => NamespaceGlob::matches($glob, $subject));
    }

    private function carriesAnnotations(QueryMatch $match): bool
    {
        if ($this->annotated === []) {
            return true;
        }

        $docblocks = '';

        foreach ([$match->function, $match->class] as $node) {
            $comment = $node instanceof Node ? $node->getDocComment() : null;
            $docblocks .= $comment instanceof Doc ? $comment->getText() : '';
        }

        return array_all($this->annotated, fn ($tag): bool => str_contains($docblocks, str_starts_with($tag, '@') ? $tag : '@'.$tag));
    }

    private function carriesAttributes(QueryMatch $match): bool
    {
        if ($this->attributes === []) {
            return true;
        }

        $names = [];

        foreach ([$match->function, $match->class] as $node) {
            if (! $node instanceof Node) {
                continue;
            }

            /** @var array<int, Node\AttributeGroup> $attrGroups */
            $attrGroups = property_exists($node, 'attrGroups') ? $node->attrGroups : [];

            foreach ($attrGroups as $group) {
                foreach ($group->attrs as $attribute) {
                    $names[] = Analyser::resolvedName($attribute->name);
                    $names[] = $attribute->name->getLast();
                }
            }
        }

        foreach ($this->attributes as $wanted) {
            $found = array_any($names, fn (string $name): bool => strcasecmp(trim($name, '\\'), trim($wanted, '\\')) === 0);
            if (! $found) {
                return false;
            }
        }

        return true;
    }

    /**
     * "The first argument is not a literal string" was written as four chained text filters in
     * the measured history, and it is the clearest case for having a query language at all:
     * expressed textually it has a long tail of false positives that have to be eyeballed.
     */
    private function argumentsAreAsRequired(QueryMatch $match): bool
    {
        foreach ($this->literalArguments as $position) {
            if (! $this->argumentIsLiteral($match->node, $position)) {
                return false;
            }
        }

        return array_all($this->nonLiteralArguments, fn (int $position): bool => $this->argumentIsLiteral($match->node, $position) === false);
    }

    /**
     * Null when there is no such argument at all, which is neither "literal" nor "not literal"
     * — a call with one argument does not answer a question about its third.
     */
    private function argumentIsLiteral(Node $node, int $position): ?bool
    {
        if (! property_exists($node, 'args')) {
            return null;
        }

        /** @var array<int, Node\Arg|Node\VariadicPlaceholder> $args */
        $args = $node->args;
        $argument = $args[$position] ?? null;

        if (! $argument instanceof Node\Arg) {
            return null;
        }

        $value = $argument->value;

        return $value instanceof Node\Scalar
            || ($value instanceof Node\Expr\ConstFetch && in_array(strtolower($value->name->toString()), ['true', 'false', 'null'], true))
            || ($value instanceof Node\Expr\Array_ && $this->everyItemIsLiteral($value));
    }

    private function everyItemIsLiteral(Node\Expr\Array_ $array): bool
    {
        return array_all($array->items, fn (ArrayItem $item): bool => $item->value instanceof Node\Scalar);
    }

    private function signatureMatches(QueryMatch $match): bool
    {
        if ($this->paramCount === null && $this->visibility === null && $this->returns === null) {
            return true;
        }

        $function = $match->function;

        if (! $function instanceof Node\FunctionLike) {
            return false;
        }

        if ($this->paramCount !== null && count($function->getParams()) !== $this->paramCount) {
            return false;
        }

        if ($this->returns !== null) {
            $declared = TypeRenderer::short($function->getReturnType());

            if ($declared === null || strcasecmp($declared, $this->returns) !== 0) {
                return false;
            }
        }

        if ($this->visibility !== null) {
            if (! $function instanceof Node\Stmt\ClassMethod) {
                return false;
            }

            $actual = match (true) {
                $function->isPrivate() => 'private',
                $function->isProtected() => 'protected',
                default => 'public',
            };

            if ($actual !== strtolower($this->visibility)) {
                return false;
            }
        }

        return true;
    }

    private function classMatches(QueryMatch $match): bool
    {
        if ($this->usesTrait === [] && $this->extends === [] && $this->implements === []) {
            return true;
        }

        $class = $match->class;

        if (! $class instanceof Node\Stmt\ClassLike) {
            return false;
        }

        $traits = [];
        $extends = [];
        $implements = [];

        foreach ($class->stmts as $statement) {
            if ($statement instanceof Node\Stmt\TraitUse) {
                foreach ($statement->traits as $trait) {
                    $traits[] = Analyser::resolvedName($trait);
                }
            }
        }

        if ($class instanceof Node\Stmt\Class_) {
            if ($class->extends instanceof Node\Name) {
                $extends[] = Analyser::resolvedName($class->extends);
            }

            foreach ($class->implements as $interface) {
                $implements[] = Analyser::resolvedName($interface);
            }
        }

        return $this->allPresent($this->usesTrait, $traits)
            && $this->allPresent($this->extends, $extends)
            && $this->allPresent($this->implements, $implements);
    }

    /**
     * @param  list<string>  $wanted
     * @param  list<string>  $actual
     */
    private function allPresent(array $wanted, array $actual): bool
    {
        foreach ($wanted as $name) {
            $found = array_any($actual, fn (string $candidate): bool => strcasecmp(trim($candidate, '\\'), trim($name, '\\')) === 0 || NamespaceGlob::matches($name, $candidate));
            if (! $found) {
                return false;
            }
        }

        return true;
    }
}

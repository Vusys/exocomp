<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Inference;

use PhpParser\Node;
use PhpParser\Node\Identifier;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;
use Vusys\Exocomp\Analysis\Analyser;
use Vusys\Exocomp\Analysis\FileAnalysis;
use Vusys\Exocomp\Index\MemberOrigin;
use Vusys\Exocomp\Index\SymbolGraph;
use Vusys\Exocomp\Symbol\Confidence;
use Vusys\Exocomp\Symbol\SymbolRef;

/**
 * Finds every reference to one symbol in one file, and says how sure it is about each.
 *
 * The interesting work is not finding the name — grep does that — it is the two judgements
 * either side of it. A same-named method on an unrelated class is *not* a reference and must be
 * excluded, or the tool is `sed` with extra steps. A same-named method on a subclass *is* one
 * and must be included, or a rename silently breaks polymorphism. Everything in between, where
 * the receiver's type cannot be established, is reported as unresolved with the reason — never
 * quietly dropped and never quietly included.
 */
final class ReferenceScanner extends NodeVisitorAbstract
{
    /** @var list<Reference> */
    private array $references = [];

    private string $namespace = '';

    /** @var list<string> */
    private array $classes = [];

    /** @var list<string> */
    private array $functions = [];

    private Scope $scope;

    /** @var array<string, bool> */
    private array $relatedness = [];

    public function __construct(
        private readonly SymbolRef $target,
        private readonly FileAnalysis $analysis,
        private readonly SymbolGraph $graph,
        private readonly TypeResolver $types,
    ) {
        $this->scope = new Scope(null, null);
    }

    public function enterNode(Node $node): ?int
    {
        $this->track($node);

        if ($node instanceof Node\Stmt) {
            $this->types->observeAnnotation($node, $this->scope, $this->analysis->imports);
        }

        if ($node instanceof Node\Expr\Assign) {
            $this->types->observeAssignment($node, $this->scope, $this->analysis->imports);
        }

        if ($this->target->hasMember()) {
            $this->memberReference($node);

            return null;
        }

        if ($this->classReference($node)) {
            // The name inside a `use` statement is the same reference as the statement itself.
            // Counting both would double every import in the answer.
            return NodeVisitor::DONT_TRAVERSE_CHILDREN;
        }

        return null;
    }

    public function leaveNode(Node $node): null
    {
        if ($node instanceof Node\Stmt\ClassLike) {
            array_pop($this->classes);
        }

        if ($node instanceof Node\FunctionLike) {
            array_pop($this->functions);
            $this->scope = $this->scopeFor(null);
        }

        return null;
    }

    /**
     * @return list<Reference>
     */
    public function references(): array
    {
        return $this->references;
    }

    private function track(Node $node): void
    {
        if ($node instanceof Node\Stmt\Namespace_) {
            $this->namespace = $node->name?->toString() ?? '';
        }

        if ($node instanceof Node\Stmt\ClassLike) {
            $this->classes[] = $node->namespacedName?->toString() ?? $node->name?->toString() ?? '(anonymous)';
        }

        if ($node instanceof Node\FunctionLike) {
            $this->functions[] = $this->functionName($node);
            $this->scope = $this->scopeFor($node);
        }
    }

    private function scopeFor(?Node\FunctionLike $function): Scope
    {
        $class = $this->classes === [] ? null : $this->classes[count($this->classes) - 1];
        $parent = $class === null ? null : $this->parentOf($class);

        return $this->types->scopeFor($class, $parent, $function, $this->analysis->imports);
    }

    private function parentOf(string $classFqn): ?string
    {
        foreach ($this->graph->ancestorsOf($classFqn) as $fqn => $origin) {
            if ($origin === MemberOrigin::Inherited) {
                return $fqn;
            }
        }

        return null;
    }

    /**
     * A reference to the class itself: every position where its name appears, resolved.
     *
     * The name resolver has already turned imported short names, relative names and aliases
     * into fully-qualified ones, which is the half a textual search cannot do — `use Acme\Http
     * \Client as HttpClient` means every later `HttpClient` is a reference to something whose
     * name never appears near it.
     */
    private function classReference(Node $node): bool
    {
        if ($node instanceof Node\UseItem) {
            if (strcasecmp($node->name->toString(), $this->target->owner) === 0) {
                $this->record($node, 'import', Confidence::Resolved, 'use statement');
            }

            return true;
        }

        if ($node instanceof Node\Name && strcasecmp(Analyser::resolvedName($node), $this->target->owner) === 0) {
            $this->record($node, 'name', Confidence::Resolved, 'resolved name');
        }

        return false;
    }

    private function memberReference(Node $node): void
    {
        $member = (string) $this->target->member;

        [$name, $receiver, $kind] = match (true) {
            $node instanceof Node\Expr\MethodCall,
            $node instanceof Node\Expr\NullsafeMethodCall => [$node->name, $node->var, 'method call'],
            $node instanceof Node\Expr\PropertyFetch,
            $node instanceof Node\Expr\NullsafePropertyFetch => [$node->name, $node->var, 'property fetch'],
            $node instanceof Node\Expr\StaticCall => [$node->name, $node->class, 'static call'],
            $node instanceof Node\Expr\StaticPropertyFetch => [$node->name, $node->class, 'static property'],
            $node instanceof Node\Expr\ClassConstFetch => [$node->name, $node->class, 'constant fetch'],
            default => [null, null, ''],
        };

        if ($name === null || $receiver === null) {
            $this->declarationReference($node, $member);

            return;
        }

        if (! $this->nameMatches($name, $member)) {
            return;
        }

        $type = $receiver instanceof Node\Name
            ? $this->staticReceiverType($receiver)
            : $this->types->typeOf($receiver, $this->scope, $this->analysis->imports);

        if (! $type->isKnown()) {
            $this->record($node, $kind, Confidence::Textual, $type->reason);

            return;
        }

        $relation = $this->relation((string) $type->fqn);

        if ($relation === null) {
            // A same-named member on an unrelated class. Not a reference, and excluding it is
            // exactly what separates this from a textual search.
            return;
        }

        // The weaker of the two. A receiver established from a docblock cannot yield a
        // resolved reference no matter how cleanly it relates to the target: the annotation is
        // a claim about the code, not a fact of it, and the write side must be able to tell the
        // difference.
        $confidence = $this->weaker($type->confidence, $relation['confidence']);
        $reason = $confidence === $type->confidence && $type->confidence !== Confidence::Resolved
            ? $type->reason.', '.$relation['reason']
            : $relation['reason'];

        $this->record($node, $kind, $confidence, $reason);
    }

    /**
     * The declaration itself, and any that override it. A caller asking about a member wants
     * the place it is defined as much as the places it is used.
     */
    private function declarationReference(Node $node, string $member): void
    {
        $name = match (true) {
            $node instanceof Node\Stmt\ClassMethod => $node->name,
            $node instanceof Node\Stmt\EnumCase => $node->name,
            default => null,
        };

        if (! $name instanceof Identifier || ! $this->nameMatches($name, $member)) {
            return;
        }

        $class = $this->classes === [] ? null : $this->classes[count($this->classes) - 1];

        if ($class === null || $this->relation($class) === null) {
            return;
        }

        $this->record($node, 'declaration', Confidence::Resolved, 'declared here');
    }

    private function staticReceiverType(Node\Name $name): ResolvedType
    {
        $keyword = $this->scope->resolveKeyword($name->toString());

        return $keyword ?? ResolvedType::resolvedBy(Analyser::resolvedName($name), 'class name');
    }

    /**
     * How a receiver of this type relates to the class the target member belongs to.
     *
     * @return array{confidence: Confidence, reason: string}|null
     */
    private function relation(string $receiverFqn): ?array
    {
        $owner = $this->target->owner;

        if (strcasecmp($receiverFqn, $owner) === 0) {
            return ['confidence' => Confidence::Resolved, 'reason' => 'receiver is '.$owner];
        }

        $key = mb_strtolower($receiverFqn);

        // Calling an inherited member through a subclass is the same member. Not following this
        // would drop every call made through a child class.
        if ($this->relatedness[$key.'>'] ??= $this->hasAncestor($receiverFqn, $owner)) {
            return ['confidence' => Confidence::Resolved, 'reason' => 'inherited from '.$owner];
        }

        // The receiver is typed as an ancestor, so the call may or may not land here at
        // runtime. Reporting it as certain would be a guess; dropping it would hide a real
        // polymorphic call site.
        if ($this->relatedness[$key.'<'] ??= $this->hasAncestor($owner, $receiverFqn)) {
            return ['confidence' => Confidence::Inferred, 'reason' => 'receiver typed as '.$receiverFqn.', may dispatch here'];
        }

        return null;
    }

    private function weaker(Confidence $left, Confidence $right): Confidence
    {
        $order = [Confidence::Resolved->value => 0, Confidence::Inferred->value => 1, Confidence::Textual->value => 2];

        return $order[$left->value] >= $order[$right->value] ? $left : $right;
    }

    private function hasAncestor(string $child, string $ancestor): bool
    {
        return array_any(array_keys($this->graph->ancestorsOf($child)), fn (string $fqn): bool => strcasecmp($fqn, $ancestor) === 0);
    }

    private function nameMatches(Node $name, string $member): bool
    {
        // `VarLikeIdentifier` extends `Identifier`, so one check covers a static property's
        // name as well as a method's.
        $text = $name instanceof Identifier ? $name->toString() : null;

        if ($text === null) {
            return false;
        }

        // Methods fold case in PHP; properties, constants and enum cases do not. `strcasecmp`
        // already covers exact equality, so there is nothing to add to it.
        return $this->target->memberIsProperty
            ? $text === $member
            : strcasecmp($text, $member) === 0;
    }

    private function record(Node $node, string $kind, Confidence $confidence, string $reason): void
    {
        $start = $node->getStartFilePos();
        $end = $node->getEndFilePos();
        $source = $start < 0 || $end < $start ? '' : $this->analysis->file->slice($start, $end);
        $flat = trim((string) preg_replace('/\s+/', ' ', $source));

        $this->references[] = new Reference(
            path: $this->analysis->file->displayPath,
            line: $node->getStartLine(),
            column: $start < 0 ? 1 : $this->analysis->file->columnAt($start),
            kind: $kind,
            confidence: $confidence,
            reason: $reason,
            snippet: strlen($flat) <= 90 ? $flat : mb_strcut($flat, 0, 90, 'UTF-8').'…',
            context: $this->context(),
        );
    }

    private function context(): string
    {
        $class = $this->classes === [] ? null : $this->classes[count($this->classes) - 1];
        $function = $this->functions === [] ? null : $this->functions[count($this->functions) - 1];

        if ($class !== null && $function !== null) {
            return $class.'::'.$function;
        }

        return $class ?? $function ?? ($this->namespace === '' ? '(global)' : $this->namespace);
    }

    private function functionName(Node\FunctionLike $node): string
    {
        return match (true) {
            $node instanceof Node\Stmt\ClassMethod => $node->name->toString(),
            $node instanceof Node\Stmt\Function_ => $node->name->toString(),
            default => '{closure}',
        };
    }
}

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
use Vusys\Exocomp\Index\ResolvedMember;
use Vusys\Exocomp\Index\SymbolGraph;
use Vusys\Exocomp\Index\SymbolRecord;
use Vusys\Exocomp\Symbol\Confidence;
use Vusys\Exocomp\Symbol\Identifier as SymbolIdentifier;
use Vusys\Exocomp\Symbol\SymbolKind;
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

    /** @var list<Scope> the scopes a closure or nested function was entered from */
    private array $enclosing = [];

    /** @var array<string, bool> */
    private array $relatedness = [];

    /** @var ?SymbolKind the kind the target member is declared as, looked up once */
    private ?SymbolKind $targetKind = null;

    private bool $targetKindResolved = false;

    private ?Members $members = null;

    private int $excluded = 0;

    /**
     * @param  list<string>  $spellings  other names a call site may use for this member
     */
    public function __construct(
        private readonly SymbolRef $target,
        private readonly FileAnalysis $analysis,
        private readonly SymbolGraph $graph,
        private readonly TypeResolver $types,
        private readonly ?Magic $magic = null,
        private readonly array $spellings = [],
        private readonly bool $targetDeclared = true,
        private readonly string $undeclared = '',
        private readonly ?EloquentChain $eloquent = null,
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

        if ($node instanceof Node\Stmt\Foreach_) {
            $this->types->observeForeach($node, $this->scope, $this->analysis->imports);
        }

        $this->types->enterNarrowing($node, $this->scope, $this->analysis->imports);

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
        $this->types->leaveNarrowing($node, $this->scope);

        if ($node instanceof Node\Stmt\ClassLike) {
            array_pop($this->classes);
        }

        if ($node instanceof Node\FunctionLike) {
            array_pop($this->functions);
            $this->scope = array_pop($this->enclosing) ?? $this->scopeFor(null);
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

    /**
     * Calls with an unknown receiver that were proved not to be on the target, and so are
     * neither listed nor unresolved. Counted, because a dropped hit nobody is told about is
     * indistinguishable from one that was never there.
     */
    public function excluded(): int
    {
        return $this->excluded;
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
            $this->enclosing[] = $this->scope;
            $this->scope = $this->scopeFor($node, $this->scope);
        }
    }

    private function scopeFor(?Node\FunctionLike $function, ?Scope $enclosing = null): Scope
    {
        $class = $this->classes === [] ? null : $this->classes[count($this->classes) - 1];
        $parent = $class === null ? null : $this->parentOf($class);

        return $this->types->scopeFor($class, $parent, $function, $this->analysis->imports, $enclosing);
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

        if (! $name instanceof Node || ! $receiver instanceof Node) {
            $this->declarationReference($node, $member);

            return;
        }

        $written = $this->written($name);
        $spelled = ! $this->nameMatches($name, $member);

        // A member reached under another name — a local scope called as `active()` — is only a
        // reference if the magic that renames it actually lands on the target. The name alone
        // proves nothing here, and following it without that check would turn every `->active()`
        // in the corpus into a hit.
        if ($spelled && ($written === null || ! in_array(strtolower($written), array_map(strtolower(...), $this->spellings), true))) {
            return;
        }

        $type = $receiver instanceof Node\Name
            ? $this->staticReceiverType($receiver)
            : $this->types->typeOf($receiver, $this->scope, $this->analysis->imports);

        if (! $type->isKnown()) {
            if ($spelled) {
                return;
            }

            if ($this->eloquent?->excludes($receiver, $this->rootType(...)) === true) {
                $this->excluded++;

                return;
            }

            $this->record($node, $kind, Confidence::Textual, $type->reason);

            return;
        }

        // A receiver typed as a class that exists nowhere, a missing `use` most often, could be
        // a call on the target under the name that should have been imported.
        if (! $spelled && $this->types->existsNowhere((string) $type->fqn)) {
            $this->record($node, $kind, Confidence::Textual, sprintf('%s: no such class is declared anywhere (a missing import?)', $type->fqn));

            return;
        }

        $related = $this->relation((string) $type->fqn);
        $relation = $spelled ? null : $related;

        // A trait method the receiver's `insteadof` replaced is not the one a call to that name
        // runs: `Pings::hello insteadof Greets` makes `$host->hello()` no caller of Greets::hello.
        if ($relation !== null && $this->magic?->excludedByInsteadof((string) $type->fqn, $this->target->owner, (string) $this->target->member) === true) {
            return;
        }

        // Asked when the hierarchy connects the receiver to the target — where a convention can
        // land — or when the receiver can hand the name somewhere else entirely.
        if ($relation === null && $written !== null && ($related !== null || $this->couldForward((string) $type->fqn))) {
            $relation = $this->throughMagic((string) $type->fqn, $written, $kind);
        }

        // Where the call lands, when that is not on the receiver's own hierarchy: `Order::find()`
        // runs `Builder::find`, `$invoices->save()` runs `HasOneOrMany::save`. The same answer
        // `calls-from` gives, so the two commands agree about what a call is.
        if ($relation === null && $written !== null && ! $spelled && ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall || $node instanceof Node\Expr\StaticCall)) {
            $landed = $this->types->callTarget((string) $type->fqn, $written, $type->confidence, $type->reason);

            if ($landed->lands !== null && SymbolIdentifier::fold($landed->lands) === SymbolIdentifier::fold($this->target->owner.'::'.$this->target->member)) {
                $relation = ['confidence' => $landed->confidence, 'reason' => sprintf('called as %s, which lands here: %s', $landed->fqn, $landed->reason)];
            }
        }

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

        // A property fetch naming a method is not the same claim as calling it. On a class with
        // `__get` it really is that member — an Eloquent relation read as an attribute is the
        // commonest example there is — and on a class without one it is a bug rather than a use.
        //
        // So the magic layer is asked first, and when it can name the mechanism its answer is
        // used instead of the caveat. Hedging with "if the class defines one" about a relation
        // the framework demonstrably routes would be hedging against something established.
        if ($this->shapeDisagrees($kind) && ! ($relation['explained'] ?? false)) {
            $explained = $written === null ? null : $this->throughMagic((string) $type->fqn, $written, $kind);

            if ($explained === null) {
                $confidence = $this->weaker($confidence, Confidence::Inferred);
                $reason .= '; read as a property, but the member is declared as a method — this resolves through __get, if the class defines one';
            } else {
                $confidence = $this->weaker($confidence, $explained['confidence']);
                $reason = $explained['reason'];
            }
        }

        // A member nothing declares, on a class read to the end of its ancestry, is not a
        // reference to a declaration — there is none. It is a name that matches, which is
        // exactly what `textual` is for.
        if (! $this->targetDeclared) {
            $confidence = Confidence::Textual;
            $reason = $this->undeclared;
        }

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

        // An ancestor's declaration is the member this one overrides, not a reference to it.
        // Counting it as `resolved` put the parent into a rename of the child, while leaving
        // the parent's other implementations out; `--family` asks for the whole hierarchy.
        if (strcasecmp($class, $this->target->owner) !== 0 && $this->hasAncestor($this->target->owner, $class)) {
            return;
        }

        $this->record($node, 'declaration', Confidence::Resolved, strcasecmp($class, $this->target->owner) === 0 ? 'declared here' : 'overrides it, in '.$class);
    }

    /**
     * Whether the way the member was reached disagrees with the way it was declared.
     */
    private function shapeDisagrees(string $kind): bool
    {
        return $kind === 'property fetch' && $this->targetKind() === SymbolKind::Method;
    }

    private function targetKind(): ?SymbolKind
    {
        if ($this->targetKindResolved) {
            return $this->targetKind;
        }

        $this->targetKindResolved = true;
        $member = (string) $this->target->member;

        foreach ($this->graph->members($this->target->owner)['members'] as $resolved) {
            if (strcasecmp($resolved->record->name, $member) === 0) {
                return $this->targetKind = $resolved->record->kind;
            }
        }

        return $this->targetKind;
    }

    private function rootType(Node $receiver): ResolvedType
    {
        return $receiver instanceof Node\Name
            ? $this->staticReceiverType($receiver)
            : $this->types->typeOf($receiver, $this->scope, $this->analysis->imports);
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
    /**
     * Whether a receiver unrelated to the target could hand the name somewhere else.
     *
     * It is only worth it if the receiver can hand the name somewhere else, and the two ways
     * that happens both start at a `__call` or a `__get`. A convention resolves to a declaration
     * on the receiver's *own* class, so it can never land on an unrelated target; asking anyway
     * costs an ancestry walk per receiver type, and a corpus-wide search meets hundreds of them.
     *
     * Answered from the index, so it costs no file read. The limit it accepts: a `@mixin` on a
     * class that declares no `__call` of its own — which the framework's base classes do declare,
     * out in `vendor/` where the index cannot see them. In one measured application, 261 of the
     * 265 `@mixin` tags point at a generated helper full of framework methods, which is not
     * something a rename of an application member ever reaches.
     */
    private function couldForward(string $receiverFqn): bool
    {
        $members = $this->members ??= new Members($this->graph);

        return $members->declared($receiverFqn, '__call', SymbolKind::Method) instanceof SymbolRecord
            || $members->declared($receiverFqn, '__get', SymbolKind::Method) instanceof SymbolRecord;
    }

    /**
     * The member reached through magic, when the hierarchy alone does not connect the receiver
     * to the target.
     *
     * Two shapes arrive here. A `@mixin` or a forwarder lands on the target under the same
     * name, and a framework convention lands on it under a different one; both have to end at a
     * declaration that really is the member being searched for, or they are not references.
     *
     * @return array{confidence: Confidence, reason: string}|null
     */
    private function throughMagic(string $receiverFqn, string $written, string $kind): ?array
    {
        $magic = str_contains($kind, 'propert')
            ? $this->magic?->property($receiverFqn, $written)
            : $this->magic?->method($receiverFqn, $written);

        if ($magic?->declaration === null) {
            return null;
        }

        $owner = $magic->owner();
        $member = $magic->member();

        if ($owner === null || $member === null || strcasecmp($member, (string) $this->target->member) !== 0) {
            return null;
        }

        $related = $this->relation($owner);

        return $related === null ? null : [
            'confidence' => Confidence::weakest($related['confidence'], $magic->confidence),
            'reason' => $magic->reason,
            'explained' => true,
        ];
    }

    /**
     * The name as it is literally written, when it is written at all — a variable member name
     * is exactly the case that has none.
     */
    private function written(Node $name): ?string
    {
        // `VarLikeIdentifier` — a static property's name — extends `Identifier`, so one check
        // covers both.
        return $name instanceof Identifier ? $name->toString() : null;
    }

    /**
     * @return array{confidence: Confidence, reason: string, explained?: bool}|null
     */
    private function relation(string $receiverFqn): ?array
    {
        $owner = $this->target->owner;

        if (strcasecmp($receiverFqn, $owner) === 0) {
            return ['confidence' => Confidence::Resolved, 'reason' => 'receiver is '.$owner];
        }

        $key = SymbolIdentifier::fold($receiverFqn);

        // Calling an inherited member through a subclass is the same member. Not following this
        // would drop every call made through a child class.
        if ($this->relatedness[$key.'>'] ??= $this->hasAncestor($receiverFqn, $owner)) {
            return ['confidence' => Confidence::Resolved, 'reason' => 'inherited from '.$owner];
        }

        // The receiver is typed as an ancestor, so the call may or may not land here at
        // runtime. Reporting it as certain would be a guess; dropping it would hide a real
        // polymorphic call site. Unless the ancestor does not declare the member at all: the
        // call then works only by accident of the object, and renaming it breaks every other
        // implementation of that type, so it is not offered as a likely reference.
        if ($this->relatedness[$key.'<'] ??= $this->hasAncestor($owner, $receiverFqn)) {
            return $this->declaresTarget($receiverFqn)
                ? ['confidence' => Confidence::Inferred, 'reason' => 'receiver typed as '.$receiverFqn.', may dispatch here']
                : ['confidence' => Confidence::Textual, 'reason' => sprintf('receiver typed as %s, which does not declare %s(); it reaches here only when the object happens to be a %s', $receiverFqn, (string) $this->target->member, $owner)];
        }

        return null;
    }

    private function declaresTarget(string $classFqn): bool
    {
        $member = (string) $this->target->member;

        return $this->graph->members($classFqn)['unreachable'] !== []
            || array_any($this->graph->members($classFqn)['members'], static fn (ResolvedMember $resolved): bool => strcasecmp($resolved->record->name, $member) === 0);
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
            default => self::closureName(
                $this->functions === [] ? null : $this->functions[count($this->functions) - 1],
                $this->classes === [] ? $this->analysis->file->displayPath : null,
                $node->getStartLine(),
            ),
        };
    }

    /**
     * A closure's name, unique and stable, in the shape PHP 8.4 uses in a stack trace: the
     * declaration it is written in and its line, `{closure:refund():12}`, or outside any class
     * its file and line, `{closure:tests/Unit/RefundTest.php:5}`. A bare `{closure}` made every
     * closure in a class one caller, and every closure outside a class one caller for the
     * whole project, so all but one vanished from `callers-of`.
     */
    public static function closureName(?string $enclosing, ?string $file, int $line): string
    {
        return match (true) {
            $enclosing !== null => sprintf('{closure:%s:%d}', str_starts_with($enclosing, '{') ? $enclosing : $enclosing.'()', $line),
            $file !== null => sprintf('{closure:%s:%d}', $file, $line),
            default => sprintf('{closure:%d}', $line),
        };
    }
}

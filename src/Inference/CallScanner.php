<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Inference;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use Vusys\Exocomp\Analysis\Analyser;
use Vusys\Exocomp\Analysis\FileAnalysis;
use Vusys\Exocomp\Index\SymbolGraph;
use Vusys\Exocomp\Index\SymbolRecord;
use Vusys\Exocomp\Symbol\Confidence;
use Vusys\Exocomp\Symbol\SymbolKind;

/**
 * The calls one declaration makes.
 *
 * This is the inverse of reference finding and it fails in the same places, for the same
 * reasons — a receiver whose type is not written down cannot be followed. The framework edges
 * that would close most of the remaining gaps in a Laravel application (a dispatched job, an
 * event, a container binding, a facade) are a later phase; until then a call through any of
 * them is reported as unresolved rather than quietly omitted, so a caller can see the graph is
 * disconnected at those joints instead of believing it is complete.
 */
final class CallScanner extends NodeVisitorAbstract
{
    /** @var list<OutgoingCall> */
    private array $calls = [];

    public function __construct(
        private readonly FileAnalysis $analysis,
        private readonly TypeResolver $types,
        private readonly Scope $scope,
        private readonly ?SymbolGraph $graph = null,
    ) {}

    private function declares(string $fqn): bool
    {
        if (! $this->graph instanceof SymbolGraph) {
            return false;
        }

        return array_any($this->graph->byFqn($fqn), fn (SymbolRecord $record): bool => $record->kind === SymbolKind::FunctionDecl);
    }

    public function enterNode(Node $node): null
    {
        if ($node instanceof Node\Stmt) {
            $this->types->observeAnnotation($node, $this->scope, $this->analysis->imports);
        }

        if ($node instanceof Node\Expr\Assign) {
            $this->types->observeAssignment($node, $this->scope, $this->analysis->imports);
        }

        if ($node instanceof Node\Stmt\Foreach_) {
            $this->types->observeForeach($node, $this->scope, $this->analysis->imports);
        }

        $this->types->enterNarrowing($node, $this->scope);

        match (true) {
            $node instanceof Node\Expr\MethodCall,
            $node instanceof Node\Expr\NullsafeMethodCall => $this->methodCall($node),
            $node instanceof Node\Expr\StaticCall => $this->staticCall($node),
            $node instanceof Node\Expr\FuncCall => $this->functionCall($node),
            $node instanceof Node\Expr\New_ => $this->instantiation($node),
            default => null,
        };

        return null;
    }

    public function leaveNode(Node $node): null
    {
        $this->types->leaveNarrowing($node, $this->scope);

        return null;
    }

    /**
     * @return list<OutgoingCall>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    private function methodCall(Node\Expr\MethodCall|Node\Expr\NullsafeMethodCall $call): void
    {
        if (! $call->name instanceof Node\Identifier) {
            $this->record($call, 'method call', null, Confidence::Textual, 'variable method name');

            return;
        }

        $receiver = $this->types->typeOf($call->var, $this->scope, $this->analysis->imports);

        if (! $receiver->isKnown()) {
            $this->record($call, 'method call', null, Confidence::Textual, $receiver->reason);

            return;
        }

        $target = $this->types->callTarget((string) $receiver->fqn, $call->name->toString(), $receiver->confidence, $receiver->reason);

        $this->record($call, 'method call', $target->fqn, $target->confidence, $target->reason, $receiver->fqn);
    }

    private function staticCall(Node\Expr\StaticCall $call): void
    {
        if (! $call->class instanceof Node\Name || ! $call->name instanceof Node\Identifier) {
            $this->record($call, 'static call', null, Confidence::Textual, 'dynamic static call');

            return;
        }

        $keyword = $this->scope->resolveKeyword($call->class->toString());
        $owner = $keyword instanceof ResolvedType ? $keyword->fqn : Analyser::resolvedName($call->class);

        if ($owner === null) {
            $this->record($call, 'static call', null, Confidence::Textual, $keyword->reason);

            return;
        }

        $target = $this->types->callTarget($owner, $call->name->toString(), Confidence::Resolved, 'class name');

        $this->record($call, 'static call', $target->fqn, $target->confidence, $target->reason, $owner);
    }

    private function functionCall(Node\Expr\FuncCall $call): void
    {
        if (! $call->name instanceof Node\Name) {
            $this->invocation($call);

            return;
        }

        // An unqualified function call falls back to the global namespace at runtime if the
        // namespaced one does not exist, so resolving it to the namespaced name unconditionally
        // would report `App\str_repeat` for a call to `str_repeat`.
        $resolved = Analyser::resolvedName($call->name);
        $global = $call->name->getLast();
        $namespaced = $call->name->isQualified() || $call->name->isFullyQualified();

        $this->record(
            $call,
            'function call',
            $namespaced || $this->declares($resolved) ? $resolved : $global,
            Confidence::Resolved,
            'function name',
        );
    }

    /**
     * A value called as though it were a function is a call to `__invoke` on its class, and
     * when the value's type is written down there is nothing dynamic about it. The call site
     * writes no method name at all, which is exactly why a textual search cannot find it.
     */
    private function invocation(Node\Expr\FuncCall $call): void
    {
        $receiver = $this->types->typeOf($call->name, $this->scope, $this->analysis->imports);

        if (! $receiver->isKnown()) {
            $this->record($call, 'function call', null, Confidence::Textual, 'dynamic function name');

            return;
        }

        $this->record($call, 'invoke', $receiver->fqn.'::__invoke', $receiver->confidence, $receiver->reason);
    }

    private function instantiation(Node\Expr\New_ $new): void
    {
        if (! $new->class instanceof Node\Name) {
            $this->record($new, 'new', null, Confidence::Textual, 'variable class name');

            return;
        }

        $this->record($new, 'new', Analyser::resolvedName($new->class).'::__construct', Confidence::Resolved, 'class name');
    }

    private function record(Node $node, string $kind, ?string $target, Confidence $confidence, string $reason, ?string $receiver = null): void
    {
        $start = $node->getStartFilePos();
        $end = $node->getEndFilePos();
        $source = $start < 0 || $end < $start ? '' : $this->analysis->file->slice($start, $end);
        $flat = trim((string) preg_replace('/\s+/', ' ', $source));

        $this->calls[] = new OutgoingCall(
            path: $this->analysis->file->displayPath,
            line: $node->getStartLine(),
            column: $start < 0 ? 1 : $this->analysis->file->columnAt($start),
            kind: $kind,
            target: $target,
            confidence: $confidence,
            reason: $reason,
            snippet: strlen($flat) <= 90 ? $flat : mb_strcut($flat, 0, 90, 'UTF-8').'…',
            receiver: $receiver,
        );
    }
}

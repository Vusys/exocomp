<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Console\Command;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Vusys\Exocomp\Analysis\Analyser;
use Vusys\Exocomp\Analysis\Declaration;
use Vusys\Exocomp\Console\CommandContext;
use Vusys\Exocomp\Console\IndexedCommand;
use Vusys\Exocomp\Inference\CallScanner;
use Vusys\Exocomp\Inference\OutgoingCall;
use Vusys\Exocomp\Inference\TypeResolver;
use Vusys\Exocomp\Output\Address;
use Vusys\Exocomp\Output\Result;
use Vusys\Exocomp\Output\Row;
use Vusys\Exocomp\Output\Section;
use Vusys\Exocomp\Project\SymbolNotFound;
use Vusys\Exocomp\Source\SourceFile;
use Vusys\Exocomp\Symbol\Confidence;
use Vusys\Exocomp\Symbol\SymbolRef;

/**
 * What this calls — the other direction, and the one that shows where the graph is
 * disconnected.
 *
 * In a framework application a great many edges leave through a dispatch, an event or a
 * container binding, and none of those is resolvable from the language alone. Every one is
 * reported as unresolved with its reason rather than dropped, so the shape of what is missing
 * is visible instead of being mistaken for an absence of calls.
 */
#[AsCommand(name: 'calls-from', description: 'List the calls a method or function makes, with their resolved targets')]
final class CallsFromCommand extends IndexedCommand
{
    protected function configure(): void
    {
        parent::configure();

        $this->addArgument('symbol', InputArgument::REQUIRED, 'A method or function address, e.g. "App\Services\OrderService::handle"');
    }

    protected function answer(InputInterface $input, CommandContext $context): Result
    {
        $notes = [];
        $graph = $this->graph($input, $context, $notes);
        $ref = SymbolRef::parse($this->stringArgument($input, 'symbol') ?? '');
        $located = $context->locator->locateOwner($ref);
        $owner = $located->declaration;

        $declaration = $ref->hasMember() ? $owner->member((string) $ref->member, false) : $owner;

        if (! $declaration instanceof Declaration) {
            throw new SymbolNotFound((string) $ref, sprintf('%s declares no member "%s"', $owner->fqn, (string) $ref->member));
        }

        $file = SourceFile::fromPath($context->project->path($located->analysis->file->displayPath), $located->analysis->file->displayPath);
        [$analysis, $statements] = new Analyser()->analyseWithAst($file);

        $target = $this->findNode($statements, $declaration->startLine, $declaration->endLine);

        if (! $target instanceof Node\FunctionLike) {
            throw new SymbolNotFound((string) $ref, sprintf('"%s" is not a function or method', (string) $ref));
        }

        $types = new TypeResolver($graph);
        $classFqn = $ref->hasMember() ? $owner->fqn : null;
        $scope = $types->scopeFor($classFqn, $owner->extends[0] ?? null, $target, $analysis->imports);

        $scanner = new CallScanner($analysis, $types, $scope, $graph);
        new NodeTraverser($scanner)->traverse([$target]);

        $calls = $scanner->calls();
        $width = Address::width(array_map(
            static fn (OutgoingCall $c): string => Address::of($c->path, $c->line, $c->column),
            $calls,
        ));

        $resolved = array_values(array_filter($calls, static fn (OutgoingCall $c): bool => $c->target !== null));
        $unsure = array_values(array_filter($calls, static fn (OutgoingCall $c): bool => $c->target === null));

        [$kept, $elided] = $context->budget->apply($resolved);

        return new Result(
            header: sprintf('%d call%s from %s', count($calls), count($calls) === 1 ? '' : 's', $declaration->fqn),
            sections: [new Section('', array_map(fn (OutgoingCall $c): Row => $this->row($c, $width), $kept))],
            unresolvedTitle: 'unresolved',
            unresolved: array_map(fn (OutgoingCall $c): Row => $this->row($c, $width, true), $unsure),
            elided: $elided,
            notes: [...$notes, ...$context->notes()],
        );
    }

    /**
     * @param  array<int, Node>  $nodes
     */
    private function findNode(array $nodes, int $startLine, int $endLine): ?Node
    {
        foreach ($nodes as $node) {
            if ($node instanceof Node\FunctionLike && $node->getStartLine() === $startLine && $node->getEndLine() === $endLine) {
                return $node;
            }

            foreach ($node->getSubNodeNames() as $name) {
                $value = $node->{$name};
                $children = array_values(array_filter(is_array($value) ? $value : [$value], static fn (mixed $c): bool => $c instanceof Node));
                $found = $this->findNode($children, $startLine, $endLine);

                if ($found instanceof Node) {
                    return $found;
                }
            }
        }

        return null;
    }

    private function row(OutgoingCall $call, int $width, bool $withReason = false): Row
    {
        return new Row(
            file: $call->path,
            line: $call->line,
            column: $call->column,
            fqn: $call->target,
            kind: $call->kind,
            text: rtrim(str_pad(Address::of($call->path, $call->line, $call->column), $width).'  '.($call->target ?? $call->snippet)),
            confidence: $call->target === null ? Confidence::Textual : $call->confidence,
            note: $withReason ? $call->reason : null,
            extra: ['reason' => $call->reason, 'source' => $call->snippet],
            carriesAddress: true,
        );
    }
}

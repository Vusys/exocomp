<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Console\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Vusys\Exocomp\Analysis\Declaration;
use Vusys\Exocomp\Console\CommandContext;
use Vusys\Exocomp\Console\IndexedCommand;
use Vusys\Exocomp\Graph\Body;
use Vusys\Exocomp\Graph\CodeEdges;
use Vusys\Exocomp\Inference\OutgoingCall;
use Vusys\Exocomp\Output\Address;
use Vusys\Exocomp\Output\Result;
use Vusys\Exocomp\Output\Row;
use Vusys\Exocomp\Output\Section;
use Vusys\Exocomp\Project\ClassSource;
use Vusys\Exocomp\Project\SymbolNotFound;
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

        // Brought up to date first, as `callers-of` is: a class written a moment ago is the
        // usual thing to ask about, and the types of its properties come from the index.
        if (! $this->boolOption($input, 'no-sync')) {
            $context->index()->freshness($this->boolOption($input, 'vendor'))->full();
        }

        $code = new CodeEdges(
            $context->project,
            $graph,
            new ClassSource($context->project, $graph, $context->locator),
            $context->locator,
        );

        $body = $code->body((string) $ref);

        if (! $body instanceof Body) {
            $this->refuse($ref, $context);
        }

        $calls = $code->callsFrom($body);

        $width = Address::width(array_map(
            static fn (OutgoingCall $c): string => Address::of($c->path, $c->line, $c->column),
            $calls,
        ));

        $resolved = array_values(array_filter($calls, static fn (OutgoingCall $c): bool => $c->target !== null));
        $unsure = array_values(array_filter($calls, static fn (OutgoingCall $c): bool => $c->target === null));

        [$kept, $elided] = $context->budget->apply($resolved);

        return new Result(
            header: sprintf('%d call%s from %s', count($calls), count($calls) === 1 ? '' : 's', $body->fqn),
            sections: [new Section('', array_map(fn (OutgoingCall $c): Row => $this->row($c, $width), $kept))],
            unresolvedTitle: 'unresolved',
            unresolved: array_map(fn (OutgoingCall $c): Row => $this->row($c, $width, true), $unsure),
            elided: $elided,
            notes: [...$notes, ...$context->notes()],
        );
    }

    /**
     * Why the declaration could not be read, said precisely.
     *
     * "Not found" covers three different situations and a caller can act on only one of them
     * at a time: the name is not in the corpus, the class is but the member is not, or the
     * address names a class where a function was wanted.
     *
     * @throws SymbolNotFound always
     */
    private function refuse(SymbolRef $ref, CommandContext $context): never
    {
        $located = $context->locator->locateOwner($ref);
        $owner = $located->declaration;

        if ($ref->hasMember() && ! $owner->member((string) $ref->member, false) instanceof Declaration) {
            throw new SymbolNotFound((string) $ref, sprintf('%s declares no member "%s"', $owner->fqn, (string) $ref->member));
        }

        throw new SymbolNotFound((string) $ref, sprintf('"%s" is not a function or method', (string) $ref));
    }

    private function row(OutgoingCall $call, int $width, bool $withReason = false): Row
    {
        return new Row(
            file: $call->path,
            line: $call->line,
            column: $call->column,
            fqn: $call->target,
            kind: $call->kind,
            // The address as reached, then where it lands when that is somewhere else.
            text: rtrim(str_pad(Address::of($call->path, $call->line, $call->column), $width).'  '.($call->target ?? $call->snippet).($call->target !== null && $call->lands !== null ? '  → '.$call->lands : '')),
            confidence: $call->target === null ? Confidence::Textual : $call->confidence,
            note: $withReason ? $call->reason : null,
            extra: ['reason' => $call->reason, 'source' => $call->snippet] + ($call->lands === null ? [] : ['lands' => $call->lands]),
            carriesAddress: true,
        );
    }
}

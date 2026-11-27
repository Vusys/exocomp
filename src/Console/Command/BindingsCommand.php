<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Console\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Vusys\Exocomp\Console\CommandContext;
use Vusys\Exocomp\Console\IndexedFrameworkCommand;
use Vusys\Exocomp\Framework\Laravel\Binding;
use Vusys\Exocomp\Output\Address;
use Vusys\Exocomp\Output\Result;
use Vusys\Exocomp\Output\Row;
use Vusys\Exocomp\Output\Section;
use Vusys\Exocomp\Project\SymbolNotFound;

/**
 * Interface to concrete, read out of the service providers.
 *
 * This is where a language-only call graph comes apart: `app(Reader::class)` returns something
 * whose class is decided in a file the call site never mentions, so `calls-from` reports the
 * edge as unresolved and is right to. The 22 measured searches for bindings were people
 * following that thread by hand.
 *
 * Contextual bindings are included because they are the least visible kind — one class asks for
 * an interface and gets a different implementation from everyone else, and nothing at either
 * end of the call says so.
 */
#[AsCommand(name: 'bindings', description: 'List container bindings: interface to concrete, including contextual ones')]
final class BindingsCommand extends IndexedFrameworkCommand
{
    protected function configure(): void
    {
        parent::configure();

        $this->addArgument('abstract', InputArgument::OPTIONAL, 'Restrict to bindings whose abstract or concrete side contains this text');
    }

    protected function answer(InputInterface $input, CommandContext $context): Result
    {
        $notes = [];
        $graph = $this->graph($input, $context, $notes);
        $reader = $this->container($input, $context, $graph);
        $filter = $this->stringArgument($input, 'abstract');

        $bindings = $this->filter($reader->bindings(), $filter);
        $declined = $this->filter($reader->declined(), $filter);

        if ($bindings === [] && $declined === []) {
            throw new SymbolNotFound($filter ?? 'bindings', sprintf(
                '%d service provider%s were read and %s',
                $reader->providersRead(),
                $reader->providersRead() === 1 ? '' : 's',
                $filter === null ? 'none of them binds anything' : sprintf('none of them binds anything matching "%s"', $filter),
            ));
        }

        [$kept, $elided] = $context->budget->apply($this->rows($bindings));

        return new Result(
            header: sprintf(
                '%d binding%s, %d service provider%s read',
                count($bindings),
                count($bindings) === 1 ? '' : 's',
                $reader->providersRead(),
                $reader->providersRead() === 1 ? '' : 's',
            ),
            sections: [new Section('', $kept)],
            unresolvedTitle: 'registered, but the concrete class is not readable',
            unresolved: $this->rows($declined, withNote: false),
            elided: $elided,
            notes: [...$notes, ...$reader->notes(), ...$context->notes()],
        );
    }

    /**
     * @param  list<Binding>  $bindings
     * @return list<Binding>
     */
    private function filter(array $bindings, ?string $needle): array
    {
        if ($needle === null || $needle === '') {
            return $bindings;
        }

        return array_values(array_filter($bindings, static fn (Binding $binding): bool => stripos($binding->abstract, $needle) !== false
            || ($binding->concrete !== null && stripos($binding->concrete, $needle) !== false)));
    }

    /**
     * @param  list<Binding>  $bindings
     * @return list<Row>
     */
    private function rows(array $bindings, bool $withNote = true): array
    {
        $width = Address::width(array_map(
            static fn (Binding $binding): string => Address::of($binding->path, $binding->line),
            $bindings,
        ));

        return array_map(
            static fn (Binding $binding): Row => new Row(
                file: $binding->path,
                line: $binding->line,
                column: null,
                fqn: $binding->concrete,
                kind: $binding->kind,
                text: rtrim(sprintf(
                    '%s  %-11s %s%s',
                    str_pad(Address::of($binding->path, $binding->line), $width),
                    $binding->kind,
                    $binding->describe(),
                    $withNote && $binding->note !== null ? '   '.$binding->note : '',
                )),
                confidence: $binding->confidence,
                note: $binding->note,
                extra: ['abstract' => $binding->abstract, 'concrete' => $binding->concrete, 'context' => $binding->context],
                carriesAddress: true,
            ),
            $bindings,
        );
    }
}

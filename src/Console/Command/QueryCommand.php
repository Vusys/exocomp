<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Console\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Vusys\Exocomp\Console\CommandContext;
use Vusys\Exocomp\Console\IndexedCommand;
use Vusys\Exocomp\Output\Address;
use Vusys\Exocomp\Output\Result;
use Vusys\Exocomp\Output\Row;
use Vusys\Exocomp\Output\Section;
use Vusys\Exocomp\Query\Pattern;
use Vusys\Exocomp\Query\Predicates;
use Vusys\Exocomp\Query\QueryEngine;
use Vusys\Exocomp\Query\QueryMatch;
use Vusys\Exocomp\Source\SourceFile;

/**
 * Structural search: the code you are looking for, written as code.
 *
 * This is what 2,170 measured multi-stage pipes were straining towards. A command like
 * `grep -rn "writeMetric(" | grep -v "writeMetric('" | grep -v 'writeMetric("' | grep -v
 * "function writeMetric"` is trying to express "call sites where the first argument is not a
 * literal string" — a structural question, approximated by four text filters, with a long tail
 * of false positives to eyeball. Here it is one call and no eyeballing.
 */
#[AsCommand(name: 'query', description: 'Search for a code shape written as PHP with $UPPERCASE metavariables')]
final class QueryCommand extends IndexedCommand
{
    protected function configure(): void
    {
        parent::configure();

        $this
            ->addArgument('pattern', InputArgument::REQUIRED, 'PHP with metavariables, e.g. \'$X->firstOrCreate($KEYS, $VALUES)\'')
            ->addOption('capture', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Show only these captures (default: all of them)')
            ->addOption('in', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only inside these namespaces, e.g. "App\Domain\**"')
            ->addOption('not-in', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Never inside these namespaces')
            ->addOption('annotated', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Enclosing declaration carries this docblock tag, e.g. "@deprecated"')
            ->addOption('has-attribute', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Enclosing declaration carries this attribute')
            ->addOption('arg-is-literal', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Argument N (0-based) is a literal')
            ->addOption('arg-is-not-literal', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Argument N (0-based) is not a literal')
            ->addOption('param-count', null, InputOption::VALUE_REQUIRED, 'Enclosing function takes exactly this many parameters')
            ->addOption('visibility', null, InputOption::VALUE_REQUIRED, 'Enclosing method is public, protected or private')
            ->addOption('returns', null, InputOption::VALUE_REQUIRED, 'Enclosing function declares this return type')
            ->addOption('uses-trait', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Enclosing class uses this trait')
            ->addOption('extends', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Enclosing class extends this')
            ->addOption('implements', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Enclosing class implements this')
            ->addOption('receiver-type', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'The call is made on a receiver of exactly this type')
            ->addOption('receiver-implements', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Its receiver implements this interface')
            ->addOption('receiver-extends', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Its receiver extends this class')
            ->setHelp(<<<'HELP'
            The pattern is PHP, and it has to parse. Three things in it are not literal:

              $UPPERCASE        any single node in an expression position, bound under that name
              _UPPERCASE        any name — a method, a declaration, a constant — bound likewise
              $_                any single node, bound to nothing
              ...               in an argument list, any number of arguments
              ()                as a parameter list, any parameters (use --param-count for exactly N)
              {}                as a body, any body

            Examples:

              exocomp query '$X->firstOrCreate($KEYS, $VALUES)' --capture KEYS
              exocomp query 'writeMetric(...)' --arg-is-not-literal 0
              exocomp query 'function _M() {}' --annotated @deprecated --in 'App\Domain\**'
              exocomp query '$R->_M($UUID)' --in 'App\Repositories\**'

            Three further predicates need type inference, and are the only ones that can fail
            for want of it: --receiver-type, --receiver-implements and --receiver-extends. A
            receiver whose type cannot be established does not match, because letting it through
            would quietly turn a scoped audit into an unscoped one.

            Exit codes: 0 matched, 1 nothing matched, 2 error.
            HELP);
    }

    protected function answer(InputInterface $input, CommandContext $context): Result
    {
        $notes = [];
        $graph = $this->graph($input, $context, $notes);

        // A structural query is corpus-wide by definition: it has no answer to validate and no
        // starting symbol to escalate from, so the index has to be complete before it starts.
        if (! $this->boolOption($input, 'no-sync')) {
            $context->index()->freshness($this->boolOption($input, 'vendor'))->full();
        }

        $pattern = Pattern::parse($this->stringArgument($input, 'pattern') ?? '');
        $predicates = $this->predicates($input);
        $engine = new QueryEngine($predicates, $graph);

        $paths = $this->candidatePaths($context, $pattern, $predicates);
        $wanted = $this->arrayOption($input, 'capture');
        $found = [];
        $undecided = [];
        $scanned = 0;

        foreach ($paths as $path) {
            $absolute = $context->project->path($path);

            if (! is_file($absolute)) {
                continue;
            }

            $scanned++;

            // Reduced to plain values before moving on to the next file. A `QueryMatch` holds
            // AST nodes, so accumulating them across a corpus-wide sweep pins every tree that
            // produced one — which exhausted the default memory limit on a large scoped query.
            [$matches, $notDecided] = $engine->runDeciding($pattern, SourceFile::fromPath($absolute, $path));

            foreach ($matches as $match) {
                $found[] = $this->summarise($match, $wanted);
            }

            foreach ($notDecided as $match) {
                $undecided[] = $this->summarise($match, $wanted);
            }
        }

        $width = Address::width(array_map(
            static fn (array $match): string => $match['address'],
            $found,
        ));

        [$kept, $elided] = $context->budget->apply($found);

        return new Result(
            // The file count is worth reporting because it is the difference between a scoped
            // question and a corpus-wide one, and a caller reading "1 file scanned" knows the
            // index did the work rather than the parser.
            header: sprintf(
                '%d match%s for %s  (%d file%s scanned)',
                count($found),
                count($found) === 1 ? '' : 'es',
                $pattern->source,
                $scanned,
                $scanned === 1 ? '' : 's',
            ),
            sections: [new Section('', array_map(
                fn (array $match): Row => $this->row($match, $width),
                $kept,
            ))],
            // Kept out of the matches, so the scope holds, and listed, so a zero is checkable.
            unresolvedTitle: 'not decided: the receiver\'s type is not known',
            unresolved: array_map(fn (array $match): Row => $this->row($match, $width), $undecided),
            elided: $elided,
            notes: [...$notes, ...$context->notes()],
        );
    }

    /**
     * Everything the output needs from a match, with no reference back to the tree it came from.
     *
     * @param  list<string>  $wanted
     * @return array{address: string, file: string, line: int, column: int, context: string, display: string, source: string, captures: array<string, string>}
     */
    private function summarise(QueryMatch $match, array $wanted): array
    {
        $captures = $match->captures->text($match->file);

        if ($wanted !== []) {
            $captures = array_intersect_key($captures, array_flip($wanted));
        }

        $rendered = [];

        foreach ($captures as $name => $value) {
            $rendered[] = $name.'='.$value;
        }

        return [
            'address' => Address::of($match->file->displayPath, $match->line(), $match->column()),
            'file' => $match->file->displayPath,
            'line' => $match->line(),
            'column' => $match->column(),
            'context' => $match->context(),
            // With nothing bound there is nothing better to show than the code itself.
            'display' => $rendered === [] ? $match->snippet() : implode('  ', $rendered),
            'source' => $match->snippet(240),
            'captures' => $match->captures->raw($match->file),
        ];
    }

    /**
     * @param  array{address: string, file: string, line: int, column: int, context: string, display: string, source: string, captures: array<string, string>}  $match
     */
    private function row(array $match, int $width): Row
    {
        return new Row(
            file: $match['file'],
            line: $match['line'],
            column: $match['column'],
            fqn: $match['context'],
            kind: 'match',
            text: rtrim(str_pad($match['address'], $width).'  '.$match['display']),
            extra: ['context' => $match['context'], 'source' => $match['source']] + $match['captures'],
            carriesAddress: true,
        );
    }

    private function predicates(InputInterface $input): Predicates
    {
        return new Predicates(
            in: $this->arrayOption($input, 'in'),
            notIn: $this->arrayOption($input, 'not-in'),
            annotated: $this->arrayOption($input, 'annotated'),
            attributes: $this->arrayOption($input, 'has-attribute'),
            literalArguments: array_map(intval(...), $this->arrayOption($input, 'arg-is-literal')),
            nonLiteralArguments: array_map(intval(...), $this->arrayOption($input, 'arg-is-not-literal')),
            paramCount: ($count = $this->stringOption($input, 'param-count')) === null ? null : (int) $count,
            visibility: $this->stringOption($input, 'visibility'),
            returns: $this->stringOption($input, 'returns'),
            usesTrait: $this->arrayOption($input, 'uses-trait'),
            extends: $this->arrayOption($input, 'extends'),
            implements: $this->arrayOption($input, 'implements'),
            receiverTypes: $this->arrayOption($input, 'receiver-type'),
            receiverImplements: $this->arrayOption($input, 'receiver-implements'),
            receiverExtends: $this->arrayOption($input, 'receiver-extends'),
        );
    }
}

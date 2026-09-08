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

            Exit codes: 0 matched, 1 nothing matched, 2 error.
            HELP);
    }

    protected function answer(InputInterface $input, CommandContext $context): Result
    {
        $notes = [];
        $this->graph($input, $context, $notes);

        // A structural query is corpus-wide by definition: it has no answer to validate and no
        // starting symbol to escalate from, so the index has to be complete before it starts.
        if (! $this->boolOption($input, 'no-sync')) {
            $context->index()->freshness($this->boolOption($input, 'vendor'))->full();
        }

        $pattern = Pattern::parse($this->stringArgument($input, 'pattern') ?? '');
        $predicates = $this->predicates($input);
        $engine = new QueryEngine($predicates);

        $paths = $this->candidatePaths($context, $pattern, $predicates);
        $matches = [];
        $scanned = 0;

        foreach ($paths as $path) {
            $absolute = $context->project->path($path);

            if (! is_file($absolute)) {
                continue;
            }

            $scanned++;
            $matches = [...$matches, ...$engine->runOn($pattern, SourceFile::fromPath($absolute, $path))];
        }

        $wanted = $this->arrayOption($input, 'capture');
        $addresses = array_map(
            static fn (QueryMatch $match): string => Address::of($match->file->displayPath, $match->line(), $match->column()),
            $matches,
        );
        $width = Address::width($addresses);

        [$kept, $elided] = $context->budget->apply($matches);

        return new Result(
            // The file count is worth reporting because it is the difference between a scoped
            // question and a corpus-wide one, and a caller reading "1 file scanned" knows the
            // index did the work rather than the parser.
            header: sprintf(
                '%d match%s for %s  (%d file%s scanned)',
                count($matches),
                count($matches) === 1 ? '' : 'es',
                $pattern->source,
                $scanned,
                $scanned === 1 ? '' : 's',
            ),
            sections: [new Section('', array_map(
                fn (QueryMatch $match): Row => $this->row($match, $width, $wanted),
                $kept,
            ))],
            elided: $elided,
            notes: [...$notes, ...$context->notes()],
        );
    }

    /**
     * A match renders as its address plus whatever it bound. With nothing bound there is
     * nothing better to show than the code itself, flattened to a line.
     *
     * @param  list<string>  $wanted
     */
    private function row(QueryMatch $match, int $width, array $wanted): Row
    {
        $captures = $match->captures->text($match->file);

        if ($wanted !== []) {
            $captures = array_intersect_key($captures, array_flip($wanted));
        }

        $rendered = [];

        foreach ($captures as $name => $value) {
            $rendered[] = $name.'='.$value;
        }

        $address = Address::of($match->file->displayPath, $match->line(), $match->column());

        return new Row(
            file: $match->file->displayPath,
            line: $match->line(),
            column: $match->column(),
            fqn: $match->context(),
            kind: 'match',
            text: rtrim(str_pad($address, $width).'  '.($rendered === [] ? $match->snippet() : implode('  ', $rendered))),
            extra: ['context' => $match->context(), 'source' => $match->snippet(240)] + $match->captures->raw($match->file),
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
        );
    }
}

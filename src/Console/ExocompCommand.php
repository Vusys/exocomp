<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Console;

use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Vusys\Exocomp\Analysis\FileAnalysis;
use Vusys\Exocomp\Framework\SourceRoots;
use Vusys\Exocomp\Output\OutputBudget;
use Vusys\Exocomp\Output\OutputFormat;
use Vusys\Exocomp\Output\Result;
use Vusys\Exocomp\Project\AutoloadMap;
use Vusys\Exocomp\Project\Project;
use Vusys\Exocomp\Project\SymbolLocator;
use Vusys\Exocomp\Project\SymbolNotFound;
use Vusys\Exocomp\Source\ParseFailure;
use Vusys\Exocomp\Source\Revision;
use Vusys\Exocomp\Symbol\SymbolRef;

/**
 * The shared spine of every command: the same options, the same rendering, the same exit codes.
 *
 * Exit codes are part of the interface, not an afterthought — 0 found, 1 not found, 2 error —
 * so that `exocomp show X && …` and CI guards mean something. Collapsing "not found" and
 * "broken invocation" into a single non-zero code would make both untrustworthy.
 */
abstract class ExocompCommand extends Command
{
    use TypedInput;
    use WritesErrors;

    protected const int EXIT_FOUND = 0;

    protected const int EXIT_NOT_FOUND = 1;

    protected const int EXIT_ERROR = 2;

    /**
     * The ceiling applied when output is not going to a terminal and the caller named none.
     *
     * Roughly 100KB of text. Chosen against the measured distribution of real answers — median
     * 1KB, ninetieth percentile 5KB, largest 13KB — so it is some eight times the biggest
     * answer anyone has actually received and cannot bite ordinary use. It is a bound on a
     * runaway, not a budget: the failure it exists to prevent is a `members` on a god object or
     * a `references-to` on a framework base class filling a caller's whole context in one go.
     */
    public const int DEFAULT_MAX_TOKENS = 25000;

    protected function configure(): void
    {
        $this
            ->addOption('project', null, InputOption::VALUE_REQUIRED, 'Project root (defaults to the nearest ancestor with a composer.json)')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: text, json, paths', OutputFormat::Text->value)
            ->addOption('json', null, InputOption::VALUE_NONE, 'Shorthand for --format=json')
            ->addOption('rev', null, InputOption::VALUE_REQUIRED, 'Read the source at a git revision instead of the working tree')
            ->addOption('stdin', null, InputOption::VALUE_NONE, 'Read the file to analyse from standard input')
            ->addOption('as', null, InputOption::VALUE_REQUIRED, 'The path to report for --stdin input', '(stdin)')
            ->addOption('scan', null, InputOption::VALUE_NONE, 'If the name cannot be placed, read every file under the source roots to find it (slow; needed only for a class sharing a file with a differently-named sibling)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum records to emit; the remainder is reported, never dropped silently. Use this, not `| head`, which hides the cut and the exit code', '0')
            ->addOption('max-tokens', null, InputOption::VALUE_REQUIRED, sprintf('Approximate ceiling on output size; any cut is reported. Defaults to %d when output is not a terminal; 0 removes it', self::DEFAULT_MAX_TOKENS), '0');
    }

    /**
     * @throws SymbolNotFound when the thing asked for does not exist (exit 1)
     * @throws ParseFailure when a file that had to be read could not be parsed (exit 2)
     * @throws InvalidArgumentException when the invocation itself is malformed (exit 2)
     * @throws RuntimeException when the work could not be done (exit 2)
     */
    abstract protected function answer(InputInterface $input, CommandContext $context): Result;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $context = $this->context($input, $output);
        } catch (InvalidArgumentException|RuntimeException $error) {
            $this->fail($output, $error->getMessage());

            return self::EXIT_ERROR;
        }

        try {
            $result = $this->answer($input, $context);
        } catch (SymbolNotFound $notFound) {
            $this->fail($output, $notFound->getMessage());

            $elsewhere = $this->declaredElsewhere($context, $notFound->address);

            foreach ($elsewhere as $note) {
                $this->fail($output, 'note: '.$note);
            }

            // A scan that could not parse a file, or an index that dropped one, must say so
            // here too. "Not found" and "could not look" are different answers.
            //
            // Except when the name was found under another namespace: a content scan cannot
            // place a class that is not there under the name asked for, so advising one would
            // send the reader to a dead end past the answer they are being handed.
            foreach ($context->notes() as $note) {
                if ($elsewhere !== [] && str_contains($note, 'content scan of the source roots')) {
                    continue;
                }

                $this->fail($output, 'note: '.$note);
            }

            return self::EXIT_NOT_FOUND;
        } catch (ParseFailure $failure) {
            $this->fail($output, sprintf('%s:%d: %s', $failure->path, $failure->startLine, $failure->getMessage()));

            return self::EXIT_ERROR;
        } catch (InvalidArgumentException|RuntimeException $error) {
            $this->fail($output, $error->getMessage());

            return self::EXIT_ERROR;
        }

        $rendered = $context->budget->capRendered(
            $context->format->renderer()->render($result->capped($context->budget)),
        );

        // Raw mode, always. The payload is PHP source and PHP source contains `<?php`, `<` in
        // comparisons and `<...>` in docblock generics — all of which Symfony's formatter would
        // read as style tags and mangle, corrupting exactly what `show` exists to deliver.
        $output->writeln($rendered, OutputInterface::OUTPUT_RAW);

        // A negative answer is still an answer and still goes to standard output — but the
        // exit code has to say "not found", or `exocomp reaches … && …` means nothing.
        return $result->found ? self::EXIT_FOUND : self::EXIT_NOT_FOUND;
    }

    /**
     * The same short name, declared under a namespace other than the one asked for.
     *
     * Guessing a namespace is the commonest way a name fails to resolve — an agent knows the
     * class is called `OrderRepository` and puts it under `App\Repositories\` when it lives
     * somewhere else entirely. The index already holds the answer, so this is a fact
     * rather than a suggestion, and it costs one lookup on a path that has already failed.
     *
     * The index is read as it stands and never built to answer this: a command that does not
     * need an index must not acquire one to improve its error message.
     *
     * @return list<string>
     */
    protected function declaredElsewhere(CommandContext $context, string $address): array
    {
        $owner = explode('::', $address)[0];

        // Nothing to offer when no namespace was guessed — the short name is the question that
        // was already asked and already answered.
        if (! str_contains($owner, '\\')) {
            return [];
        }

        $short = (string) array_slice(explode('\\', $owner), -1)[0];

        try {
            $found = $context->index()->graph(validate: false)->byName($short);
        } catch (RuntimeException) {
            return [];
        }

        $names = [];

        foreach ($found as $record) {
            if ($record->isClassLike() && strcasecmp($record->fqn, $owner) !== 0) {
                $names[$record->fqn] = true;
            }
        }

        $names = array_keys($names);

        if ($names === []) {
            return [];
        }

        return [sprintf(
            '"%s" is declared as %s%s',
            $short,
            implode(', ', array_slice($names, 0, 3)),
            count($names) > 3 ? sprintf(' and %d more — try: exocomp locate %s', count($names) - 3, $short) : '',
        )];
    }

    private function fail(OutputInterface $output, string $message): void
    {
        $this->errorOutput($output)->writeln(
            sprintf('exocomp %s: %s', $this->getName() ?? '', $message),
            OutputInterface::OUTPUT_RAW,
        );
    }

    /**
     * Commands that take a "file or class" target accept either, because an agent has one or
     * the other to hand and should not have to convert between them first.
     */
    protected function targetFile(string $target, CommandContext $context): FileAnalysis
    {
        if (is_file($target)) {
            return $context->locator->analyse($target);
        }

        return $context->locator->locateOwner(SymbolRef::parse($target))->analysis;
    }

    /**
     * The buffer on standard input, analysed as though it were a file.
     */
    /**
     * The stream `--stdin` reads from. A test has to be able to supply one; a fork per case
     * would make exercising the flag cost more than the flag.
     *
     * @var resource|null
     */
    public static $standardInput;

    protected function stdinFile(InputInterface $input, CommandContext $context): FileAnalysis
    {
        $stream = self::$standardInput ?? STDIN;
        $contents = stream_get_contents($stream);

        if ($contents === false || $contents === '') {
            throw new RuntimeException('--stdin was given but nothing arrived on standard input');
        }

        return $context->locator->analyseString($contents, $this->stringOption($input, 'as') ?? '(stdin)');
    }

    protected function readingStdin(InputInterface $input): bool
    {
        return $this->boolOption($input, 'stdin');
    }

    /**
     * Whether the answer is being read by a person at a terminal or piped into something else.
     *
     * The distinction decides whether a token ceiling applies by default, so it is read off the
     * actual output stream rather than guessed: a test harness writing to a memory buffer is
     * correctly not a terminal, and gets exactly the behaviour an agent's pipe gets.
     */
    private function writingToATerminal(OutputInterface $output): bool
    {
        $stream = $output instanceof StreamOutput ? $output->getStream() : null;

        return is_resource($stream) && stream_isatty($stream);
    }

    /**
     * The token ceiling for this run.
     *
     * An explicit `--max-tokens` always wins, including `--max-tokens 0`, which is how a caller
     * asks for no ceiling at all and must keep working — an answer someone deliberately wants
     * whole has to arrive whole.
     */
    private function tokenCeiling(InputInterface $input, OutputInterface $output): int
    {
        if ($input->hasParameterOption('--max-tokens')) {
            return $this->intOption($input, 'max-tokens', 0);
        }

        return $this->writingToATerminal($output) ? 0 : self::DEFAULT_MAX_TOKENS;
    }

    private function context(InputInterface $input, OutputInterface $output): CommandContext
    {
        $root = $this->stringOption($input, 'project');
        $project = $root === null
            ? Project::discover((string) getcwd())
            : Project::at($root);

        $reference = $this->stringOption($input, 'rev');
        $revision = $reference === null ? null : new Revision($project->root, $reference);
        $revision?->resolve();

        $format = $this->boolOption($input, 'json')
            ? OutputFormat::Json
            : OutputFormat::parse($this->stringOption($input, 'format') ?? OutputFormat::Text->value);

        $map = SourceRoots::augment($project, AutoloadMap::forProject($project));

        return new CommandContext(
            project: $project,
            map: $map,
            locator: new SymbolLocator($project, $map, $this->boolOption($input, 'scan'), $revision),
            budget: new OutputBudget(
                limit: $this->intOption($input, 'limit', 0),
                maxTokens: $this->tokenCeiling($input, $output),
                implicit: ! $input->hasParameterOption('--max-tokens'),
            ),
            format: $format,
            revision: $reference,
        );
    }
}

<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Console;

use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vusys\Exocomp\Analysis\FileAnalysis;
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
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum records to emit; the remainder is reported, never dropped silently', '0')
            ->addOption('max-tokens', null, InputOption::VALUE_REQUIRED, 'Approximate ceiling on output size; any cut is reported', '0');
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
            $context = $this->context($input);
        } catch (InvalidArgumentException|RuntimeException $error) {
            $this->fail($output, $error->getMessage());

            return self::EXIT_ERROR;
        }

        try {
            $result = $this->answer($input, $context);
        } catch (SymbolNotFound $notFound) {
            $this->fail($output, $notFound->getMessage());

            // A scan that could not parse a file, or an index that dropped one, must say so
            // here too. "Not found" and "could not look" are different answers.
            foreach ($context->notes() as $note) {
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

        $rendered = $context->budget->capRendered($context->format->renderer()->render($result));

        // Raw mode, always. The payload is PHP source and PHP source contains `<?php`, `<` in
        // comparisons and `<...>` in docblock generics — all of which Symfony's formatter would
        // read as style tags and mangle, corrupting exactly what `show` exists to deliver.
        $output->writeln($rendered, OutputInterface::OUTPUT_RAW);

        return self::EXIT_FOUND;
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

    private function context(InputInterface $input): CommandContext
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

        $map = AutoloadMap::forProject($project);

        return new CommandContext(
            project: $project,
            map: $map,
            locator: new SymbolLocator($project, $map, $this->boolOption($input, 'scan'), $revision),
            budget: new OutputBudget(
                limit: $this->intOption($input, 'limit', 0),
                maxTokens: $this->intOption($input, 'max-tokens', 0),
            ),
            format: $format,
            revision: $reference,
        );
    }
}

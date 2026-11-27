<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Console;

use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vusys\Exocomp\Analysis\FileAnalysis;
use Vusys\Exocomp\Framework\SourceRoots;
use Vusys\Exocomp\Index\Index;
use Vusys\Exocomp\Output\JsonRenderer;
use Vusys\Exocomp\Output\OutputBudget;
use Vusys\Exocomp\Output\OutputFormat;
use Vusys\Exocomp\Output\Result;
use Vusys\Exocomp\Output\Row;
use Vusys\Exocomp\Output\Section;
use Vusys\Exocomp\Output\UnresolvedGroup;
use Vusys\Exocomp\Project\AutoloadMap;
use Vusys\Exocomp\Project\Project;
use Vusys\Exocomp\Project\SymbolLocator;
use Vusys\Exocomp\Project\SymbolNotFound;
use Vusys\Exocomp\Source\ParseFailure;
use Vusys\Exocomp\Source\Revision;
use Vusys\Exocomp\Symbol\Confidence;
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
     * Not found, but not everything could be checked: edges not followed, routes not walked,
     * files not searched or not parsed, receivers whose type is unknown. `if exocomp …` reads
     * it as false, as it does `1`; only code that tells the two apart sees a difference.
     */
    protected const int EXIT_INCOMPLETE = 3;

    /**
     * The ceiling every answer is held to unless the caller names another: one page.
     *
     * About 16KB of text, the same at a terminal and in a pipe, so how much an answer costs is
     * known before it is asked. The measured distribution of real answers (median 1KB, ninetieth
     * percentile 5KB) fits on one page. A bigger one is cut on a record boundary, keeps its
     * summary, and says how to get the next page, so nothing is ever lost to `| head`.
     */
    public const int DEFAULT_MAX_TOKENS = 4000;

    /** How long a cut answer is kept, so that its next pages do not repeat the search. */
    private const int PAGE_CACHE_SECONDS = 600;

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
            ->addOption('max-tokens', null, InputOption::VALUE_REQUIRED, sprintf('Approximate size of one page of output, in tokens; a longer answer is cut on a record boundary and says how to get the next page. Defaults to %d; 0 removes it', self::DEFAULT_MAX_TOKENS), '0')
            ->addOption('page', null, InputOption::VALUE_REQUIRED, 'Which page of a cut answer to print; each page repeats the summary', '1')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Print the whole answer, however long (the same as --max-tokens 0)');
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
            $result = $this->cachedPage($input, $context) ?? $this->answer($input, $context);
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

        $renderer = $context->format->renderer();
        $capped = $result->capped($context->budget);
        $rendered = $context->budget->renderWithin(
            $capped,
            $renderer->render(...),
            $renderer instanceof JsonRenderer,
            $this->intOption($input, 'page', 1),
            $this->commandLine($input),
        );

        // Kept briefly when it took more than one page, so asking for the next does not repeat
        // a search that may have taken seconds.
        if ($context->budget->maxTokens > 0 && OutputBudget::tokens($renderer->render($capped)) > $context->budget->maxTokens) {
            $this->keepPage($input, $context, $result);
        }

        // Raw mode, always. The payload is PHP source and PHP source contains `<?php`, `<` in
        // comparisons and `<...>` in docblock generics — all of which Symfony's formatter would
        // read as style tags and mangle, corrupting exactly what `show` exists to deliver.
        $output->writeln($rendered, OutputInterface::OUTPUT_RAW);

        // A negative answer is still an answer and still goes to standard output — but the
        // exit code has to say "not found", or `exocomp reaches … && …` means nothing.
        return match (true) {
            $result->found => self::EXIT_FOUND,
            $result->complete => self::EXIT_NOT_FOUND,
            default => self::EXIT_INCOMPLETE,
        };
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
        $file = $this->existingFile($target, $context);

        if ($file !== null) {
            return $context->locator->analyse($file);
        }

        return $context->locator->locateOwner(SymbolRef::parse($target))->analysis;
    }

    /**
     * A file target as given, or relative to the project root, as every path this tool prints
     * is. Something shaped like a path that is neither is a missing file, not a class name.
     */
    protected function existingFile(string $target, CommandContext $context): ?string
    {
        foreach ([$target, $context->project->path($target)] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        if (str_ends_with(strtolower($target), '.php')) {
            throw new SymbolNotFound($target, sprintf('no file %s, here or under the project root %s', $target, $context->project->root));
        }

        return null;
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
     * The token ceiling for this run.
     *
     * An explicit `--max-tokens` always wins, including `--max-tokens 0`, which is how a caller
     * asks for no ceiling at all and must keep working — an answer someone deliberately wants
     * whole has to arrive whole.
     */
    private function tokenCeiling(InputInterface $input): int
    {
        if ($this->boolOption($input, 'all')) {
            return 0;
        }

        if ($input->hasParameterOption('--max-tokens')) {
            return $this->intOption($input, 'max-tokens', 0);
        }

        return self::DEFAULT_MAX_TOKENS;
    }

    /**
     * The command as typed, without the page it asked for: what the next page is asked by.
     */
    private function commandLine(InputInterface $input): string
    {
        $typed = $input instanceof ArgvInput ? (string) $input : (string) $this->getName();
        $typed = trim((string) preg_replace('/\s--page(?:=|\s+)\S+/', '', ' '.$typed));

        return 'exocomp '.$typed;
    }

    private function pageKey(InputInterface $input, CommandContext $context): string
    {
        $stamp = @filemtime(Index::cachePath()) ?: 0;
        $typed = trim((string) preg_replace('/\s--(?:page|max-tokens)(?:=|\s+)\S+|\s--all\b/', '', ' '.$this->commandLine($input)));

        return Index::cacheDirectory().'/pages/'.hash('xxh128', $context->project->root."\0".$typed."\0".$stamp).'.ser';
    }

    /**
     * The answer a first page was cut from, for a later page asked for soon after.
     */
    private function cachedPage(InputInterface $input, CommandContext $context): ?Result
    {
        if ($this->intOption($input, 'page', 1) <= 1) {
            return null;
        }

        $path = $this->pageKey($input, $context);

        if (! is_file($path) || time() - (int) filemtime($path) > self::PAGE_CACHE_SECONDS) {
            return null;
        }

        $result = @unserialize((string) file_get_contents($path), ['allowed_classes' => [Result::class, Section::class, Row::class, UnresolvedGroup::class, Confidence::class]]);

        return $result instanceof Result ? $result : null;
    }

    private function keepPage(InputInterface $input, CommandContext $context, Result $result): void
    {
        $path = $this->pageKey($input, $context);

        if (! is_dir(dirname($path))) {
            @mkdir(dirname($path), 0o777, true);
        }

        @file_put_contents($path, serialize($result));
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

        $map = SourceRoots::augment($project, AutoloadMap::forProject($project));

        return new CommandContext(
            project: $project,
            map: $map,
            locator: new SymbolLocator($project, $map, $this->boolOption($input, 'scan'), $revision),
            budget: new OutputBudget(
                limit: $this->intOption($input, 'limit', 0),
                maxTokens: $this->tokenCeiling($input),
                implicit: ! $input->hasParameterOption('--max-tokens'),
            ),
            format: $format,
            revision: $reference,
        );
    }
}

<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Console;

use Symfony\Component\Console\Application as SymfonyApplication;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\RuntimeException as ConsoleRuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Vusys\Exocomp\Console\Command\BindingsCommand;
use Vusys\Exocomp\Console\Command\CallersOfCommand;
use Vusys\Exocomp\Console\Command\CallsFromCommand;
use Vusys\Exocomp\Console\Command\CheckCommand;
use Vusys\Exocomp\Console\Command\ConfigCommand;
use Vusys\Exocomp\Console\Command\DispatchesCommand;
use Vusys\Exocomp\Console\Command\FacadeCommand;
use Vusys\Exocomp\Console\Command\GraphNeighbourhoodCommand;
use Vusys\Exocomp\Console\Command\ImplementorsCommand;
use Vusys\Exocomp\Console\Command\ImportsCommand;
use Vusys\Exocomp\Console\Command\IndexCommand;
use Vusys\Exocomp\Console\Command\LocateCommand;
use Vusys\Exocomp\Console\Command\MembersCommand;
use Vusys\Exocomp\Console\Command\ModelCommand;
use Vusys\Exocomp\Console\Command\MorphMapCommand;
use Vusys\Exocomp\Console\Command\OutlineCommand;
use Vusys\Exocomp\Console\Command\OverridesCommand;
use Vusys\Exocomp\Console\Command\QueryCommand;
use Vusys\Exocomp\Console\Command\ReachesCommand;
use Vusys\Exocomp\Console\Command\ReferencesToCommand;
use Vusys\Exocomp\Console\Command\RoutesCommand;
use Vusys\Exocomp\Console\Command\SchemaCommand;
use Vusys\Exocomp\Console\Command\ShowCommand;
use Vusys\Exocomp\Console\Command\SubclassesCommand;
use Vusys\Exocomp\Console\Command\TraitsUsersCommand;

final class Application extends SymfonyApplication
{
    public const string VERSION = '0.8.0';

    public function __construct()
    {
        parent::__construct('exocomp', self::VERSION);

        $this->addCommands([
            new ShowCommand,
            new OutlineCommand,
            new MembersCommand,
            new ImportsCommand,
            new LocateCommand,
            new SubclassesCommand,
            new ImplementorsCommand,
            new TraitsUsersCommand,
            new OverridesCommand,
            new ReferencesToCommand,
            new CallersOfCommand,
            new CallsFromCommand,
            new QueryCommand,
            new CheckCommand,
            new ModelCommand,
            new ConfigCommand,
            new FacadeCommand,
            new BindingsCommand,
            new MorphMapCommand,
            new DispatchesCommand,
            new RoutesCommand,
            new SchemaCommand,
            new ReachesCommand,
            new GraphNeighbourhoodCommand,
            new IndexCommand,
        ]);
    }

    /**
     * A command line that does not parse is an error, exit 2, and says what was probably meant.
     *
     * Symfony exits 1 for these, which this tool's contract reserves for "not found" — so an
     * invented `callers-of --depth 3` read as an empty answer. The suggestion is the nearest
     * spelling among this command's own options, or failing that the commands that do take the
     * option, since an invented flag is usually a feature that lives next door.
     */
    protected function doRunCommand(Command $command, InputInterface $input, OutputInterface $output): int
    {
        try {
            return parent::doRunCommand($command, $input, $output);
        } catch (ConsoleRuntimeException $error) {
            // Not chained: the original would be rendered again beneath the replacement.
            throw new ConsoleRuntimeException($this->suggest($error->getMessage(), $command), 2, $error);
        }
    }

    private function suggest(string $message, Command $command): string
    {
        if (preg_match('/"--?([\w-]+)" option does not exist/', $message, $matches) !== 1) {
            return $message;
        }

        $typed = $matches[1];
        $own = array_keys($command->getDefinition()->getOptions());
        $near = array_values(array_filter($own, static fn (string $name): bool => levenshtein($typed, $name) <= 2 || str_starts_with($name, $typed)));

        if ($near !== []) {
            return sprintf('%s Did you mean --%s?', $message, implode(' or --', $near));
        }

        $elsewhere = [];

        foreach ($this->all() as $name => $other) {
            if ($other !== $command && ! $other->isHidden() && $other->getDefinition()->hasOption($typed)) {
                $elsewhere[$name] = true;
            }
        }

        // The invention actually seen: a depth on a one-level search. Saying where depth lives
        // is not enough on its own, because `graph` walks the other way.
        $levels = in_array($command->getName(), ['callers-of', 'references-to'], true) && in_array($typed, ['depth', 'recursive', 'transitive', 'levels'], true)
            ? sprintf(' %s answers one level: run it again on a caller to go further, or ask `reaches <from> <to>` whether a path exists.', $command->getName())
            : '';

        return match (true) {
            $levels !== '' => $message.$levels,
            $elsewhere === [] => sprintf('%s See `exocomp %s --help` for the options it takes.', $message, $command->getName()),
            default => sprintf('%s --%s is an option of: %s.', $message, $typed, implode(', ', array_keys($elsewhere))),
        };
    }

    /**
     * Colour only for a terminal, whatever the environment says.
     *
     * Symfony also colours whenever `FORCE_COLOR` is set, and that variable leaks into the
     * shells agents run commands in. Escape codes in a stream piped to `grep` can get it classed
     * as binary, and then it matches nothing without saying why. `--ansi` still forces colour,
     * because that is a request made of this command rather than a setting it inherited.
     * `NO_COLOR` is honoured by Symfony before any of this runs.
     *
     * Standard output and standard error share one formatter, so colour is on only when both
     * are terminals: `2>&1` into a pipe is the common case, and has to come out plain.
     */
    protected function configureIO(InputInterface $input, OutputInterface $output): void
    {
        parent::configureIO($input, $output);

        if ($input->hasParameterOption(['--ansi'], true)) {
            return;
        }

        $streams = [$output];

        if ($output instanceof ConsoleOutputInterface) {
            $streams[] = $output->getErrorOutput();
        }

        foreach ($streams as $stream) {
            if (! $stream instanceof StreamOutput || ! stream_isatty($stream->getStream())) {
                $output->setDecorated(false);
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Support;

use LogicException;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A capturable output with a genuinely separate error channel.
 *
 * `BufferedOutput` alone would merge the two, and merging them would hide the contract the
 * tests are here to protect: stdout is the answer and nothing else, so a caller piping into
 * `jq` or `xargs` never finds a diagnostic among the records.
 */
final class BufferedConsoleOutput extends BufferedOutput implements ConsoleOutputInterface
{
    private OutputInterface $errorOutput;

    public function __construct()
    {
        parent::__construct(self::VERBOSITY_NORMAL, decorated: false);

        $this->errorOutput = new BufferedOutput(self::VERBOSITY_NORMAL, decorated: false);
    }

    public function getErrorOutput(): OutputInterface
    {
        return $this->errorOutput;
    }

    public function setErrorOutput(OutputInterface $error): void
    {
        $this->errorOutput = $error;
    }

    public function errors(): string
    {
        return $this->errorOutput instanceof BufferedOutput ? $this->errorOutput->fetch() : '';
    }

    public function section(): ConsoleSectionOutput
    {
        throw new LogicException('sections are not used by exocomp');
    }
}

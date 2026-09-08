<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Console;

use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Routes error messages to the real stderr channel when one exists, and falls back to the
 * given output otherwise — `CommandTester` uses a plain `StreamOutput`, which has no separate
 * error channel to route to.
 *
 * Errors go to stderr so that stdout stays exactly the answer: a caller piping `exocomp` into
 * `jq` or `xargs` must never find a diagnostic mixed into the records.
 */
trait WritesErrors
{
    protected function errorOutput(OutputInterface $output): OutputInterface
    {
        return $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }
}

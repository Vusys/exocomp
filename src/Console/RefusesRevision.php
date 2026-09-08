<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Console;

use InvalidArgumentException;

/**
 * For commands whose answer can only describe the working tree.
 *
 * Accepting `--rev` and ignoring it would answer about today while claiming to answer about
 * then — the confidently-wrong failure in its purest form. Refusing is the honest option, and
 * the message names the commands that do read a revision so the caller is not left guessing
 * which half of the tool understands the flag.
 */
trait RefusesRevision
{
    protected function refuseRevision(CommandContext $context, string $because): void
    {
        if ($context->revision === null) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            '--rev is not available for %s, because %s. '
            .'show, outline and imports read a revision directly and do support it.',
            $this->getName() ?? 'this command',
            $because,
        ));
    }
}

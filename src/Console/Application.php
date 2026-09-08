<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Console;

use Symfony\Component\Console\Application as SymfonyApplication;
use Vusys\Exocomp\Console\Command\ImportsCommand;
use Vusys\Exocomp\Console\Command\MembersCommand;
use Vusys\Exocomp\Console\Command\OutlineCommand;
use Vusys\Exocomp\Console\Command\ShowCommand;

final class Application extends SymfonyApplication
{
    public const string VERSION = '0.1.0';

    public function __construct()
    {
        parent::__construct('exocomp', self::VERSION);

        $this->addCommands([
            new ShowCommand,
            new OutlineCommand,
            new MembersCommand,
            new ImportsCommand,
        ]);
    }
}

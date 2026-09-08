<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Console;

use Symfony\Component\Console\Application as SymfonyApplication;
use Vusys\Exocomp\Console\Command\CallersOfCommand;
use Vusys\Exocomp\Console\Command\CallsFromCommand;
use Vusys\Exocomp\Console\Command\CheckCommand;
use Vusys\Exocomp\Console\Command\ImplementorsCommand;
use Vusys\Exocomp\Console\Command\ImportsCommand;
use Vusys\Exocomp\Console\Command\IndexCommand;
use Vusys\Exocomp\Console\Command\LocateCommand;
use Vusys\Exocomp\Console\Command\MembersCommand;
use Vusys\Exocomp\Console\Command\OutlineCommand;
use Vusys\Exocomp\Console\Command\OverridesCommand;
use Vusys\Exocomp\Console\Command\QueryCommand;
use Vusys\Exocomp\Console\Command\ReferencesToCommand;
use Vusys\Exocomp\Console\Command\SchemaCommand;
use Vusys\Exocomp\Console\Command\ShowCommand;
use Vusys\Exocomp\Console\Command\SubclassesCommand;
use Vusys\Exocomp\Console\Command\TraitsUsersCommand;

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
            new SchemaCommand,
            new IndexCommand,
        ]);
    }
}

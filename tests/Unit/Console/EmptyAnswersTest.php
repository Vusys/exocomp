<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Tests\Support\Cli;
use Vusys\Exocomp\Tests\Support\TemporaryProject;

/**
 * Every command, asked about something it has nothing to list for, exits 1 or 3 — never 0.
 *
 * `Result` used to default to "found", so a command that forgot to say otherwise reported
 * success on an empty answer (#111). `subclasses` did, and a script reading its exit code took
 * "no subclasses" for "here they are".
 */
final class EmptyAnswersTest extends TestCase
{
    private static ?TemporaryProject $project = null;

    public static function setUpBeforeClass(): void
    {
        self::$project = TemporaryProject::copyOfLaravel();
        self::$project->write('app/Lonely/Alone.php', <<<'PHP'
            <?php

            namespace App\Lonely;

            interface Unimplemented {}

            trait Unused {}

            class Alone
            {
                public function quiet(): void {}
            }
            PHP);
        self::$project->write('app/Lonely/Bare.php', "<?php\n\nnamespace App\\Lonely;\n\nfinal class Bare {}\n");
    }

    public static function tearDownAfterClass(): void
    {
        self::$project?->destroy();
        self::$project = null;
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function emptyAnswers(): iterable
    {
        yield 'callers-of a method nothing calls' => [['callers-of', 'App\Lonely\Alone::quiet']];
        yield 'references-to a class nothing names' => [['references-to', 'App\Lonely\Bare']];
        yield 'calls-from a method that calls nothing' => [['calls-from', 'App\Lonely\Alone::quiet']];
        yield 'members of a class with none' => [['members', 'App\Lonely\Bare']];
        yield 'subclasses of a class nothing extends' => [['subclasses', 'App\Lonely\Alone']];
        yield 'implementors of an interface nothing implements' => [['implementors', 'App\Lonely\Unimplemented']];
        yield 'traits-users of a trait nothing uses' => [['traits-users', 'App\Lonely\Unused']];
        yield 'overrides of a method nothing overrides' => [['overrides', 'App\Lonely\Alone::quiet']];
        yield 'imports of a file with none' => [['imports', 'app/Lonely/Bare.php']];
        yield 'query with no match' => [['query', '$X->neverWrittenAnywhere()']];
        yield 'locate a name nothing declares' => [['locate', 'NeverDeclaredAnywhere']];
        yield 'graph of a method that calls nothing' => [['graph', 'App\Lonely\Alone::quiet']];
        yield 'reaches with no path' => [['reaches', 'App\Lonely\Alone::quiet', 'App\Models\Order::save']];
        yield 'dispatches of something never dispatched' => [['dispatches', 'App\Lonely\Bare']];
        yield 'routes matching nothing' => [['routes', 'never-a-route']];
        yield 'config key nothing defines' => [['config', 'never.defined.key']];
        yield 'schema of a table nothing creates' => [['schema', 'never_created']];
        yield 'morph-map entry that is not there' => [['morph-map', 'never-an-alias']];
        yield 'bindings of a class nothing binds' => [['bindings', 'App\Lonely\Bare']];
    }

    /**
     * @param  list<string>  $arguments
     */
    #[DataProvider('emptyAnswers')]
    public function test_an_empty_answer_never_exits_zero(array $arguments): void
    {
        $run = Cli::run([...$arguments, '--project', (string) self::$project?->root]);

        $this->assertContains($run->exitCode, [1, 3], implode(' ', $arguments)."\n".$run->output.$run->errors);
    }
}

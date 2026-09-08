<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Support;

use Symfony\Component\Console\Input\ArgvInput;
use Vusys\Exocomp\Console\Application;

/**
 * Runs the real application the way a shell would, so the tests exercise argument parsing,
 * exit codes and the raw-output path rather than reaching past them into the command objects.
 */
final class Cli
{
    /**
     * @param  list<string>  $arguments
     */
    public static function run(array $arguments): Run
    {
        $application = new Application;
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);

        $output = new BufferedConsoleOutput;
        $exitCode = $application->run(new ArgvInput(['exocomp', ...$arguments]), $output);

        return new Run($exitCode, $output->fetch(), $output->errors());
    }

    /**
     * Runs against the fixture corpus, with paths reported relative to its root so assertions
     * do not depend on where the checkout lives.
     *
     * @param  list<string>  $arguments
     */
    public static function inCorpus(array $arguments): Run
    {
        $corpus = self::corpusRoot();

        $arguments = array_map(
            static fn (string $argument): string => is_file($corpus.'/'.$argument) ? $corpus.'/'.$argument : $argument,
            $arguments,
        );

        return self::run([...$arguments, '--project', $corpus]);
    }

    public static function corpusRoot(): string
    {
        return dirname(__DIR__).'/Fixtures/corpus';
    }

    public static function corpusFile(string $relative): string
    {
        return self::corpusRoot().'/'.ltrim($relative, '/');
    }
}

<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Support;

use Symfony\Component\Console\Input\ArgvInput;
use Vusys\Exocomp\Console\Application;
use Vusys\Exocomp\Console\ExocompCommand;

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

    /**
     * Runs with a buffer standing in for standard input.
     *
     * @param  resource  $stdin
     * @param  list<string>  $arguments
     */
    public static function runWithInput($stdin, array $arguments): Run
    {
        ExocompCommand::$standardInput = $stdin;

        try {
            return self::run($arguments);
        } finally {
            ExocompCommand::$standardInput = null;
        }
    }

    /**
     * Runs against a throwaway project rather than the shared corpus.
     *
     * @param  list<string>  $arguments
     */
    public static function inProject(TemporaryProject $project, array $arguments): Run
    {
        return self::run([...$arguments, '--project', $project->root]);
    }

    /**
     * Runs against the Tier E fixture — a Laravel-shaped application that is never installed
     * and never booted, so the framework profile is exercised the only way it will ever run in
     * anger: by reading files.
     *
     * @param  list<string>  $arguments
     */
    public static function inLaravel(array $arguments): Run
    {
        return self::run([...$arguments, '--project', self::laravelRoot()]);
    }

    public static function laravelRoot(): string
    {
        return __DIR__.'/../Fixtures/laravel';
    }

    public static function corpusRoot(): string
    {
        return __DIR__.'/../Fixtures/corpus';
    }

    public static function corpusFile(string $relative): string
    {
        return self::corpusRoot().'/'.ltrim($relative, '/');
    }
}

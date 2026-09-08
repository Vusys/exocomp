<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Console\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Vusys\Exocomp\Console\CommandContext;
use Vusys\Exocomp\Console\IndexedFrameworkCommand;
use Vusys\Exocomp\Framework\Laravel\RouteDefinition;
use Vusys\Exocomp\Framework\Laravel\RouteNamespace;
use Vusys\Exocomp\Framework\Laravel\RouteReader;
use Vusys\Exocomp\Output\Address;
use Vusys\Exocomp\Output\Result;
use Vusys\Exocomp\Output\Row;
use Vusys\Exocomp\Output\Section;
use Vusys\Exocomp\Project\ClassSource;
use Vusys\Exocomp\Project\SymbolNotFound;

/**
 * The route table, read from the route files rather than from a booted application.
 *
 * `php artisan route:list` answers this better — when the application boots, which in a
 * disposable worktree with no `.env` and no database it does not. Reading the files works
 * everywhere, and it also gives the line each route was declared on, which `route:list` does
 * not.
 *
 * Groups are what make this worth doing: a URI is the concatenation of every enclosing prefix,
 * so the line that declares a route says almost nothing about the path it answers on.
 */
#[AsCommand(name: 'routes', description: 'List routes with their controller and action, resolved through group prefixes')]
final class RoutesCommand extends IndexedFrameworkCommand
{
    protected function configure(): void
    {
        parent::configure();

        $this
            ->addArgument('filter', InputArgument::OPTIONAL, 'Show only routes whose URI, name or target contains this text')
            ->addOption('method', null, InputOption::VALUE_REQUIRED, 'Show only one HTTP method')
            ->addOption('middleware', null, InputOption::VALUE_NONE, 'Include each route\'s middleware stack');
    }

    protected function answer(InputInterface $input, CommandContext $context): Result
    {
        $this->refuseRevision($context, 'it reads the route files as they stand');

        $notes = [];
        $graph = $this->graph($input, $context, $notes);
        $profile = $this->laravel($context);

        // The root namespace is what makes a string-named controller a name anything else can
        // be asked about. Without it the answer names classes that do not exist.
        $reader = new RouteReader(new RouteNamespace($graph, new ClassSource($context->project, $graph, $context->locator))->root());
        $reader->readDirectory(
            $profile->routesDirectory($context->project),
            static fn (string $absolute): string => $context->project->displayPath($absolute),
        );

        $filter = $this->stringArgument($input, 'filter');
        $method = $this->stringOption($input, 'method');
        $routes = $this->filter($reader->routes(), $filter, $method);
        $declined = $this->filter($reader->declined(), $filter, $method);

        if ($routes === [] && $declined === []) {
            throw new SymbolNotFound($filter ?? 'routes', sprintf(
                '%d route file%s were read and nothing in them matches',
                $reader->filesRead(),
                $reader->filesRead() === 1 ? '' : 's',
            ));
        }

        [$kept, $elided] = $context->budget->apply($this->rows($routes, $this->boolOption($input, 'middleware')));

        return new Result(
            header: sprintf('%d route%s from %d file%s', count($routes), count($routes) === 1 ? '' : 's', $reader->filesRead(), $reader->filesRead() === 1 ? '' : 's'),
            sections: [new Section('', $kept)],
            unresolvedTitle: 'declared, but not readable',
            unresolved: $this->rows($declined, false, withNote: false),
            elided: $elided,
            notes: [...$notes, ...$reader->notes(), ...$context->notes()],
        );
    }

    /**
     * @param  list<RouteDefinition>  $routes
     * @return list<RouteDefinition>
     */
    private function filter(array $routes, ?string $needle, ?string $method): array
    {
        return array_values(array_filter($routes, static function (RouteDefinition $route) use ($needle, $method): bool {
            if ($method !== null && stripos($route->method, $method) === false) {
                return false;
            }

            if ($needle === null || $needle === '') {
                return true;
            }

            return stripos($route->uri, $needle) !== false
                || stripos($route->target(), $needle) !== false
                || ($route->name !== null && stripos($route->name, $needle) !== false);
        }));
    }

    /**
     * @param  list<RouteDefinition>  $routes
     * @return list<Row>
     */
    private function rows(array $routes, bool $withMiddleware, bool $withNote = true): array
    {
        $width = Address::width(array_map(
            static fn (RouteDefinition $route): string => Address::of($route->path, $route->line),
            $routes,
        ));

        $uriWidth = 0;

        foreach ($routes as $route) {
            $uriWidth = max($uriWidth, min(strlen($route->uri), 48));
        }

        return array_map(
            static fn (RouteDefinition $route): Row => new Row(
                file: $route->path,
                line: $route->line,
                column: null,
                fqn: $route->controller,
                kind: 'route',
                text: rtrim(sprintf(
                    '%s  %-10s %s %s%s%s',
                    str_pad(Address::of($route->path, $route->line), $width),
                    $route->method,
                    str_pad($route->uri, $uriWidth),
                    $route->target(),
                    $withMiddleware && $route->middleware !== [] ? '   ['.implode(' ', $route->middleware).']' : '',
                    $withNote && $route->note !== null ? '   '.$route->note : '',
                )),
                confidence: $route->confidence,
                note: $route->note,
                extra: [
                    'method' => $route->method,
                    'uri' => $route->uri,
                    'controller' => $route->controller,
                    'action' => $route->action,
                    'name' => $route->name,
                    'middleware' => implode(' ', $route->middleware),
                ],
                carriesAddress: true,
            ),
            $routes,
        );
    }
}

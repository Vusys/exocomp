<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Console;

use Symfony\Component\Console\Input\InputInterface;
use Vusys\Exocomp\Framework\Laravel\ConfigEntry;
use Vusys\Exocomp\Framework\Laravel\ConfigReader;
use Vusys\Exocomp\Framework\Laravel\ContainerReader;
use Vusys\Exocomp\Index\SymbolGraph;
use Vusys\Exocomp\Index\SymbolRecord;
use Vusys\Exocomp\Project\ClassSource;
use Vusys\Exocomp\Project\SymbolNotFound;

/**
 * The base for framework answers that also need the corpus — a model's ancestry, a facade's
 * accessor, the class a binding resolves to.
 */
abstract class IndexedFrameworkCommand extends IndexedCommand
{
    use DetectsFramework;

    /**
     * The class a name refers to, saying so when the name refers to more than one.
     *
     * Short names collide, and in a large application they collide a lot: one measured corpus
     * has five queued jobs, in five namespaces, sharing a single short name. Picking the first
     * and answering about it is correct as far as it goes, and silently picking the first is how
     * someone reads an answer about a class they were not asking about. The others are named so
     * the caller can ask again with the one they meant.
     *
     * @param  list<SymbolRecord>  $records
     * @param  list<string>  $notes
     */
    protected function resolveClass(array $records, string $name, array &$notes): SymbolRecord
    {
        $classes = array_values(array_filter($records, static fn (SymbolRecord $record): bool => $record->isClassLike()));

        if ($classes === []) {
            throw new SymbolNotFound($name, sprintf('nothing named "%s" is declared in the indexed source roots', $name));
        }

        if (count($classes) > 1) {
            $notes[] = sprintf(
                '%d classes are named "%s"; this answers about %s. The others: %s',
                count($classes),
                $name,
                $classes[0]->fqn,
                implode(', ', array_map(static fn (SymbolRecord $record): string => $record->fqn, array_slice($classes, 1))),
            );
        }

        return $classes[0];
    }

    /**
     * The container, read out of every service provider in the corpus.
     *
     * A full synchronisation comes first. Providers are found by walking the inheritance graph
     * downwards from `ServiceProvider`, and a provider written since the last pass is not in
     * that graph — so the answer would be short one provider's worth of bindings, with nothing
     * to say that it was.
     */
    protected function container(InputInterface $input, CommandContext $context, SymbolGraph $graph): ContainerReader
    {
        if (! $this->boolOption($input, 'no-sync')) {
            $context->index()->freshness($this->boolOption($input, 'vendor'))->full();
        }

        $profile = $this->laravel($context);
        $config = new ConfigReader;
        $config->readDirectory(
            $profile->configDirectory($context->project),
            static fn (string $absolute): string => $context->project->displayPath($absolute),
        );

        return new ContainerReader($graph, new ClassSource($context->project, $graph, $context->locator))->read(
            /** @return list<ConfigEntry> */
            static fn (string $key): array => $config->matching($key),
        );
    }
}

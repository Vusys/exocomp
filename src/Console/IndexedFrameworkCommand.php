<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Console;

use Symfony\Component\Console\Input\InputInterface;
use Vusys\Exocomp\Framework\Laravel\ConfigEntry;
use Vusys\Exocomp\Framework\Laravel\ConfigReader;
use Vusys\Exocomp\Framework\Laravel\ContainerReader;
use Vusys\Exocomp\Index\SymbolGraph;
use Vusys\Exocomp\Project\ClassSource;

/**
 * The base for framework answers that also need the corpus — a model's ancestry, a facade's
 * accessor, the class a binding resolves to.
 */
abstract class IndexedFrameworkCommand extends IndexedCommand
{
    use DetectsFramework;

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

        return new ContainerReader($graph, new ClassSource($context->project, $graph))->read(
            /** @return list<ConfigEntry> */
            static fn (string $key): array => $config->matching($key),
        );
    }
}

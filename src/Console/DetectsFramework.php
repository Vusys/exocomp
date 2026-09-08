<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Console;

use RuntimeException;
use Vusys\Exocomp\Framework\Detection;
use Vusys\Exocomp\Framework\Laravel\LaravelProfile;

/**
 * Finding the framework profile that applies to a checkout, or saying plainly that none does.
 *
 * There is one profile today. The registry is still a list rather than a hardcoded call
 * because the seam is the point: everything the profile knows is derived by parsing ordinary
 * PHP, and a second profile would have to earn its place the same way.
 */
trait DetectsFramework
{
    protected function laravel(CommandContext $context): LaravelProfile
    {
        $profile = new LaravelProfile;
        $detection = $profile->detect($context->project);

        if (! $detection instanceof Detection) {
            throw new RuntimeException(sprintf(
                '%s answers questions about a Laravel application, and %s does not look like one — '
                .'no laravel/framework requirement in composer.json, and no artisan alongside an app/ directory. '
                .'Use --project to point at the application root if it is elsewhere.',
                $this->getName() ?? 'this command',
                $context->project->root,
            ));
        }

        return $profile;
    }

    protected function detected(CommandContext $context): string
    {
        $detection = new LaravelProfile()->detect($context->project);

        return $detection instanceof Detection ? $detection->describe() : 'no framework detected';
    }
}

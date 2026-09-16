<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Framework;

use Vusys\Exocomp\Framework\Laravel\LaravelProfile;
use Vusys\Exocomp\Project\AutoloadMap;
use Vusys\Exocomp\Project\Project;

/**
 * Where the project's own code is, once the framework has had its say.
 *
 * Composer's autoload sections are the authority on most of it and the whole of it on a plain
 * PHP project. They are not the whole of it on a framework that loads a directory by scanning:
 * Laravel's `database/migrations` is full of anonymous classes that no autoloader could map,
 * and a tool that derives its source roots from the autoload map alone cannot see a single one
 * of them. Measured on a real application, that is a four-figure count of files holding the
 * only written record of how a table came to look the way it does.
 *
 * This is the composition point, and it exists as its own class so that {@see AutoloadMap}
 * keeps meaning exactly one thing — what Composer declares — while the layer that knows about
 * frameworks does the adding.
 */
final class SourceRoots
{
    /**
     * The map, told about any directories the detected framework loads by convention.
     *
     * Unrecognised projects get the map back unchanged, so nothing here can cost a checkout
     * that is not a framework application anything at all.
     */
    public static function augment(Project $project, AutoloadMap $map): AutoloadMap
    {
        $extra = self::conventional($project);

        return $extra === [] ? $map : $map->withRoots($extra);
    }

    /**
     * @return list<string>
     */
    private static function conventional(Project $project): array
    {
        $laravel = new LaravelProfile;

        if (! $laravel->detect($project) instanceof Detection) {
            return [];
        }

        return $laravel->sourceRoots($project);
    }
}

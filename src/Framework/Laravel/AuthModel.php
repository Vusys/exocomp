<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Framework\Laravel;

use Closure;
use PhpParser\Node;
use PhpParser\NodeFinder;
use Vusys\Exocomp\Analysis\Analyser;
use Vusys\Exocomp\Analysis\LiteralValue;
use Vusys\Exocomp\Project\Project;
use Vusys\Exocomp\Source\ParseFailure;
use Vusys\Exocomp\Source\SourceFile;

/**
 * The class `auth()->user()` returns: the model `config/auth.php` names for its user providers.
 *
 * The framework's own signature says `Authenticatable|null`, a contract nothing is declared on,
 * so every chain through the signed-in user stopped there. The config file says which model
 * that is, as `User::class` or `env('AUTH_MODEL', User::class)`. Where its providers name more
 * than one model, a guard could hand back either, and there is no answer.
 */
final readonly class AuthModel
{
    /**
     * {@see forProject()}, read on first use and kept.
     *
     * @return Closure(): array{?string, string}
     */
    public static function lazily(Project $project): Closure
    {
        $found = null;

        return static function () use ($project, &$found): array {
            return $found ??= self::forProject($project);
        };
    }

    /**
     * @return array{?string, string} the class, or null, and where it came from
     */
    public static function forProject(Project $project): array
    {
        $path = $project->path('config/auth.php');

        if (! is_file($path)) {
            return [null, 'there is no config/auth.php to name its model'];
        }

        try {
            [, $statements] = new Analyser()->analyseWithAst(SourceFile::fromPath($path, 'config/auth.php'));
        } catch (ParseFailure) {
            return [null, 'config/auth.php could not be parsed'];
        }

        $models = [];

        // shortcut: every `'model'` key in the file, not only those under `providers`; fine while auth.php has no other.
        foreach (new NodeFinder()->findInstanceOf($statements, Node\ArrayItem::class) as $item) {
            if ($item->key instanceof Node\Scalar\String_ && $item->key->value === 'model') {
                $model = self::className($item->value);

                if ($model === null) {
                    return [null, 'a user provider in config/auth.php names its model in a way this tool cannot read'];
                }

                $models[strtolower($model)] = $model;
            }
        }

        return match (count($models)) {
            0 => [null, 'config/auth.php names no user provider model'],
            1 => [array_values($models)[0], 'the user provider model in config/auth.php'],
            default => [null, 'config/auth.php names more than one user provider model'],
        };
    }

    /** `User::class`, or the default of `env('AUTH_MODEL', User::class)`. */
    private static function className(Node\Expr $value): ?string
    {
        if ($value instanceof Node\Expr\FuncCall && $value->name instanceof Node\Name && Analyser::isGlobalFunction($value->name, 'env')) {
            $default = $value->getArgs()[1]->value ?? null;

            return $default === null ? null : LiteralValue::className($default);
        }

        return LiteralValue::className($value);
    }
}

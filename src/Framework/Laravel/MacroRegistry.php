<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Framework\Laravel;

use PhpParser\Node;
use PhpParser\NodeFinder;
use Vusys\Exocomp\Analysis\Analyser;
use Vusys\Exocomp\Analysis\LiteralValue;
use Vusys\Exocomp\Index\SymbolGraph;
use Vusys\Exocomp\Inference\MagicMember;
use Vusys\Exocomp\Inference\MagicSource;
use Vusys\Exocomp\Project\ClassSource;
use Vusys\Exocomp\Project\Project;
use Vusys\Exocomp\Source\ParseFailure;
use Vusys\Exocomp\Source\SourceFile;

/**
 * Members bolted onto a class from somewhere else entirely: `Builder::macro('name', $closure)`.
 *
 * The design report calls macros genuinely unresolvable, and that is right about the part that
 * matters — nothing static can say whether the provider that registers one has run on the path
 * being asked about. It is not right that there is nothing to say. The registration is a literal
 * string and a closure with a readable body, sitting at a line number, and "a macro of this name
 * is registered at `app/Providers/AppServiceProvider.php:31`" is an answer a reader can act on
 * where "this call goes nowhere I can see" is not.
 *
 * So it names the registration and never the target. The closure is not a declaration and this
 * does not pretend it is one: there is nothing for a rename to rewrite and nothing for the walk
 * to step into, and saying otherwise would be inventing a declaration out of an argument.
 *
 * Worth knowing how little this rung carries: two registrations in the `app/` tree of a measured
 * Laravel application and thirty across the whole of its `vendor/`. It is built for consistency
 * with the rest of the ladder rather than for its yield.
 */
final class MacroRegistry implements MagicSource
{
    /** @var array<string, list<array{receiver: string, path: string, line: int}>>|null */
    private ?array $registry = null;

    public function __construct(
        private readonly Project $project,
        private readonly SymbolGraph $graph,
        private readonly ClassSource $source,
        private readonly Analyser $analyser = new Analyser,
    ) {}

    public function method(string $ownerFqn, string $name): ?MagicMember
    {
        foreach ($this->registry()[$name] ?? [] as $registration) {
            if (! $this->applies($ownerFqn, $registration['receiver'])) {
                continue;
            }

            return MagicMember::claimed(null, sprintf(
                'a macro of this name is registered on %s at %s:%d — whether it is registered on this path is not something the source says',
                $registration['receiver'],
                $registration['path'],
                $registration['line'],
            ));
        }

        return null;
    }

    /**
     * Macros are methods. `Macroable` routes property reads nowhere.
     */
    public function property(string $ownerFqn, string $name): ?MagicMember
    {
        return null;
    }

    /**
     * A macro is registered under exactly the name the call site writes.
     *
     * @return list<string>
     */
    public function spellings(string $ownerFqn, string $declaredName): array
    {
        return [];
    }

    private function applies(string $ownerFqn, string $receiver): bool
    {
        return strcasecmp($ownerFqn, $receiver) === 0 || $this->source->descendsFrom($ownerFqn, $receiver);
    }

    /**
     * Every `::macro()` call in the corpus, by the name it registers.
     *
     * Built once, on the first miss that gets this far, and from the index's record of which
     * files mention `macro` at all — so a project that registers none pays one indexed lookup
     * and nothing else.
     *
     * @return array<string, list<array{receiver: string, path: string, line: int}>>
     */
    private function registry(): array
    {
        if ($this->registry !== null) {
            return $this->registry;
        }

        $registry = [];

        foreach ($this->graph->pathsMentioning('macro') as $path) {
            $absolute = $this->project->path($path);

            if (! is_file($absolute)) {
                continue;
            }

            try {
                [, $statements] = $this->analyser->analyseWithAst(SourceFile::fromPath($absolute, $path));
            } catch (ParseFailure) {
                // One unreadable file costs its own registrations and nothing else.
                continue;
            }

            foreach (new NodeFinder()->findInstanceOf($statements, Node\Expr\StaticCall::class) as $call) {
                $this->record($registry, $call, $path);
            }
        }

        return $this->registry = $registry;
    }

    /**
     * @param  array<string, list<array{receiver: string, path: string, line: int}>>  $registry
     */
    private function record(array &$registry, Node\Expr\StaticCall $call, string $path): void
    {
        if (! $call->name instanceof Node\Identifier || strcasecmp($call->name->toString(), 'macro') !== 0) {
            return;
        }

        if (! $call->class instanceof Node\Name || $call->args === []) {
            return;
        }

        $first = $call->args[0];

        if (! $first instanceof Node\Arg) {
            return;
        }

        $name = LiteralValue::scalar($first->value);

        // Only a literal name registers something findable. `Builder::macro($computed, ...)` is
        // the shape this rung has to decline, and it declines it by producing nothing.
        if (! is_string($name) || $name === '') {
            return;
        }

        $registry[$name][] = [
            'receiver' => Analyser::resolvedName($call->class),
            'path' => $path,
            'line' => $call->getStartLine(),
        ];
    }
}

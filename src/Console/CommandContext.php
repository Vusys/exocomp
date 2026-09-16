<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Console;

use RuntimeException;
use Vusys\Exocomp\Index\Index;
use Vusys\Exocomp\Output\OutputBudget;
use Vusys\Exocomp\Output\OutputFormat;
use Vusys\Exocomp\Project\AutoloadMap;
use Vusys\Exocomp\Project\Project;
use Vusys\Exocomp\Project\SymbolLocator;

/**
 * Everything a command needs that comes from the shared options rather than from its own
 * arguments.
 *
 * The index is opened lazily. Commands that can answer from the parser alone must not pay for a
 * database they never read, and on a cold cache opening one would also create it.
 */
final class CommandContext
{
    private ?Index $index = null;

    public function __construct(
        public readonly Project $project,
        public readonly AutoloadMap $map,
        public readonly SymbolLocator $locator,
        public readonly OutputBudget $budget,
        public readonly OutputFormat $format,
        public readonly ?string $revision = null,
    ) {
        $locator->correctWith($this->soleDeclarationOf(...));
    }

    /**
     * The one name really declared with this one's short name, or null when there is not
     * exactly one.
     *
     * This is what turns a guessed namespace from a failed command into an answered one. It is
     * deliberately strict: a short name carried by two classes is a genuine ambiguity, and
     * picking either would be the kind of guess that makes every other answer untrustworthy.
     *
     * The index is read exactly as it stands and never built to answer this — a command that
     * did not need an index must not acquire one because a name was mistyped. A cold cache
     * therefore declines to correct, which is the right way round: silence, not a wrong name.
     */
    private function soleDeclarationOf(string $owner): ?string
    {
        $short = (string) array_slice(explode('\\', $owner), -1)[0];

        if ($short === '') {
            return null;
        }

        try {
            $found = $this->index()->graph(validate: false)->byName($short);
        } catch (RuntimeException) {
            return null;
        }

        $names = [];

        foreach ($found as $record) {
            if ($record->isClassLike()) {
                $names[$record->fqn] = true;
            }
        }

        return count($names) === 1 ? array_key_first($names) : null;
    }

    public function index(): Index
    {
        return $this->index ??= Index::open($this->project, $this->map);
    }

    /**
     * Everything that happened underneath the answer and the caller should know about — files
     * that could not be read, an index that had to catch up, a source that stopped parsing.
     *
     * These matter most on the paths that fail. "Nothing found" and "the only file that could
     * have answered stopped parsing a moment ago" are very different facts, and reporting the
     * first while knowing the second is how a search gets wrongly believed.
     *
     * @return list<string>
     */
    public function notes(): array
    {
        $notes = $this->locator->notes();

        if ($this->index instanceof Index) {
            return [...$notes, ...$this->index->freshness()->notes()];
        }

        return $notes;
    }
}

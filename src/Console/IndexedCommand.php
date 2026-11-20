<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Console;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Vusys\Exocomp\Index\Index;
use Vusys\Exocomp\Index\SymbolGraph;
use Vusys\Exocomp\Index\SymbolRecord;
use Vusys\Exocomp\Inference\MemberElsewhere;
use Vusys\Exocomp\Output\Address;
use Vusys\Exocomp\Output\Row;
use Vusys\Exocomp\Output\UnresolvedGroup;
use Vusys\Exocomp\Project\SymbolNotFound;
use Vusys\Exocomp\Query\Pattern;
use Vusys\Exocomp\Query\Predicates;
use Vusys\Exocomp\Symbol\Confidence;
use Vusys\Exocomp\Symbol\SymbolRef;

/**
 * The base for commands that need the whole corpus rather than one file.
 *
 * It exists mainly to hold one policy: **the index is never a precondition.** A cold cache is
 * brought up to date here, silently when it is already current and with a note when it was not,
 * rather than the command refusing and telling the caller to run a build step. That refusal is
 * the specific behaviour that stopped the obvious prior art from ever being used, and repeating
 * it would repeat the outcome.
 */
abstract class IndexedCommand extends ExocompCommand
{
    use RefusesRevision;

    /**
     * Comfortably inside the two minutes an agent's shell commonly allows. A search cut off at
     * the limit is reported as incomplete; one killed by the caller's timeout reports nothing.
     */
    protected const int DEFAULT_TIME_LIMIT = 60;

    protected function configure(): void
    {
        parent::configure();

        $this->addOption('no-sync', null, InputOption::VALUE_NONE, 'Answer from the index as it stands, without checking the working tree for changes');
        $this->addOption('vendor', null, InputOption::VALUE_NONE, 'Index dependencies too; once they are indexed they stay indexed (exocomp index --no-vendor removes them)');
    }

    /**
     * For the commands that search the corpus for references, whose cost is set by how common
     * the name is rather than by how big the answer is: `create` is written in thousands of
     * files, and every one of them has to be parsed and its receivers typed.
     */
    protected function addTimeLimitOption(): void
    {
        $this->addOption('time-limit', null, InputOption::VALUE_REQUIRED, 'Stop opening files after this many seconds and answer with what was found, saying how much was not searched; 0 removes the limit', (string) self::DEFAULT_TIME_LIMIT);
    }

    protected function addUnresolvedOption(): void
    {
        $this->addOption('all-unresolved', null, InputOption::VALUE_NONE, sprintf('List every unresolved hit, rather than a count per reason with %d examples each once there are more than %d', UnresolvedGroup::EXAMPLES, UnresolvedGroup::THRESHOLD));
    }

    /**
     * The trailing block as rows, or summarised by reason when it would bury the answer.
     *
     * @param  list<Row>  $rows
     * @return array{list<Row>, list<UnresolvedGroup>}
     */
    protected function unresolvedBlock(array $rows, InputInterface $input): array
    {
        if (count($rows) <= UnresolvedGroup::THRESHOLD || $this->boolOption($input, 'all-unresolved')) {
            return [$rows, []];
        }

        return [[], UnresolvedGroup::summarise($rows)];
    }

    protected function searchDeadline(InputInterface $input): ?float
    {
        $seconds = $this->intOption($input, 'time-limit', self::DEFAULT_TIME_LIMIT);

        return $seconds > 0 ? microtime(true) + $seconds : null;
    }

    /**
     * A progress line for a person watching a long search, redrawn in place. Only for a
     * terminal: anything reading a pipe or a captured stream gets the result and nothing else.
     *
     * @return (callable(int, int): void)|null
     */
    protected function searchProgress(string $what): ?callable
    {
        if (! stream_isatty(STDERR)) {
            return null;
        }

        $last = 0.0;

        return static function (int $searched, int $of) use ($what, &$last): void {
            $now = microtime(true);

            if ($now - $last < 0.25 && $searched + 1 < $of) {
                return;
            }

            $last = $now;
            fwrite(STDERR, sprintf("\r\033[Ksearching %d/%d files that mention %s", $searched + 1, $of, $what));

            if ($searched + 1 === $of) {
                fwrite(STDERR, "\r\033[K");
            }
        };
    }

    /**
     * What a search cut short by the time limit left out: a marker for the header, where the
     * commands that search already say what they could not see, and a note — which `--json`
     * carries too — saying how much and what to do about it.
     *
     * @return array{string, list<string>}
     */
    protected function incompleteSearch(int $unsearched, InputInterface $input): array
    {
        if ($unsearched === 0) {
            return ['', []];
        }

        return [
            sprintf('  — incomplete, %d file%s not searched', $unsearched, $unsearched === 1 ? '' : 's'),
            [sprintf(
                'stopped at the %ds time limit with %d candidate file%s not searched, so this is a lower bound (--time-limit N to raise it, 0 to remove it)',
                $this->intOption($input, 'time-limit', self::DEFAULT_TIME_LIMIT),
                $unsearched,
                $unsearched === 1 ? '' : 's',
            )],
        ];
    }

    /**
     * The address checked before anything is searched for it.
     *
     * A search answers about whatever it is given, so a misspelt or guessed class came back as
     * "0 callers" or "no path", exit 0 or 1, as if it were the truth about a real class. Here a
     * class the index does not hold is placed the way `show` places it: through the autoload
     * map, and corrected where exactly one class carries that short name (the locator's note
     * says so). Anything else is an error naming the miss, never an empty answer.
     *
     * @throws SymbolNotFound
     */
    protected function knownAddress(SymbolRef $ref, SymbolGraph $graph, CommandContext $context): SymbolRef
    {
        if ($graph->namedClassLike($ref->owner) instanceof SymbolRecord) {
            return $ref;
        }

        try {
            $located = $context->locator->locateOwner(SymbolRef::parse($ref->owner));
        } catch (SymbolNotFound) {
            $short = (string) array_slice(explode('\\', $ref->owner), -1)[0];

            throw new SymbolNotFound((string) $ref, sprintf(
                'nothing is declared as "%s" in the index or where Composer would place it — check the name with: exocomp locate %s',
                $ref->owner,
                $short,
            ));
        }

        $fqn = $located->declaration->fqn;

        if (strcasecmp($fqn, $ref->owner) === 0) {
            return $ref;
        }

        return SymbolRef::parse($fqn.($ref->hasMember() ? '::'.($ref->memberIsProperty ? '$' : '').$ref->member : ''));
    }

    /**
     * The member a search should be for. A member asked for on a class that neither declares
     * nor inherits it is usually on a trait or a subclass instead; when exactly one related
     * class declares it, the search is for that one, and the notes say so. Otherwise the
     * question stands as asked, and the notes say where the name is declared.
     *
     * @param  list<string>  $notes
     */
    protected function searchTarget(SymbolRef $target, SymbolGraph $graph, array &$notes): SymbolRef
    {
        if (! $target->hasMember() || ! $graph->classLike($target->owner) instanceof SymbolRecord) {
            return $target;
        }

        $found = new MemberElsewhere($graph)->find($target);

        if ($found['reachable']) {
            return $target;
        }

        if ($found['note'] !== null) {
            $notes[] = $found['note'];
        }

        $answer = $found['answer'];

        return $answer instanceof SymbolRecord && $answer->owner !== null
            ? SymbolRef::parse($answer->owner.'::'.($target->memberIsProperty ? '$' : '').$target->member)
            : $target;
    }

    /**
     * The graph, with validation attached unless the caller has opted out.
     *
     * Nothing is synchronised up front. The index is brought level with the working tree as
     * answers are read out of it, so a query costs what its answer costs rather than what the
     * corpus costs.
     *
     * @param  list<string>  $notes  filled in with anything that changed underneath
     */
    protected function graph(InputInterface $input, CommandContext $context, array &$notes): SymbolGraph
    {
        $this->refuseRevision($context, 'the index describes the working tree');

        // Accepted and then ignored, a buffer on standard input made an answer about the file on
        // disk read as an answer about the buffer.
        if ($this->boolOption($input, 'stdin')) {
            throw new \InvalidArgumentException(sprintf(
                '--stdin is not available for %s, because it answers from the index of the working tree. show, outline and imports read a buffer from standard input.',
                $this->getName() ?? 'this command',
            ));
        }

        $index = $context->index();
        $vendor = $this->boolOption($input, 'vendor');

        if ($this->boolOption($input, 'no-sync')) {
            $notes[] = 'answered from the index as it stands, without checking the working tree (--no-sync)';

            return $index->graph(validate: false);
        }

        return $index->graph(validate: true, includeVendor: $vendor);
    }

    /**
     * A record rendered for a result set that spans files: address first, then what it is.
     *
     * @param  array<string, string|int|bool|null>  $extra
     */
    protected function located(SymbolRecord $record, int $addressWidth, string $suffix = '', array $extra = []): Row
    {
        $address = Address::of($record->path, $record->line, $record->column);

        return new Row(
            file: $record->path,
            line: $record->line,
            column: $record->column,
            fqn: $record->fqn,
            kind: $record->kind->value,
            text: rtrim(str_pad($address, $addressWidth).'  '.$record->fqn.$suffix),
            confidence: Confidence::Resolved,
            extra: ['signature' => $record->signature] + $extra,
            carriesAddress: true,
        );
    }

    /**
     * @param  list<SymbolRecord>  $records
     */
    protected function addressWidth(array $records): int
    {
        return Address::width(array_map(
            static fn (SymbolRecord $record): string => Address::of($record->path, $record->line, $record->column),
            $records,
        ));
    }

    protected function indexOf(CommandContext $context): Index
    {
        return $context->index();
    }

    /**
     * The files a pattern could possibly match, narrowed as far as the index allows.
     *
     * Two filters compose here, and both are exact rather than heuristic: `--in` restricts by
     * the namespaces a file declares, and the pattern's own literal names restrict by what a
     * file mentions. Neither can exclude a file that would have matched.
     *
     * @return list<string>
     */
    protected function candidatePaths(CommandContext $context, Pattern $pattern, Predicates $predicates): array
    {
        $store = $context->index()->store;
        $root = $context->project->root;

        $byNamespace = $store->paths($root, $predicates->namespacePrefixes());
        $required = $pattern->requiredNames();

        if ($required === []) {
            return $byNamespace;
        }

        $byName = $store->pathsMentioning($root, $required);

        if ($predicates->namespacePrefixes() === []) {
            return $byName;
        }

        return array_values(array_intersect($byNamespace, $byName));
    }
}

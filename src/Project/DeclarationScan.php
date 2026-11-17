<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Project;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Vusys\Exocomp\Analysis\Declaration;
use Vusys\Exocomp\Index\SymbolRecord;
use Vusys\Exocomp\Source\ParseFailure;
use Vusys\Exocomp\Symbol\SymbolKind;

/**
 * Finding a declaration by reading the source, when the index says there is none.
 *
 * This exists because of a specific and nasty failure seen in real sessions. An index that has
 * not finished its first pass over a checkout answers "nothing named X is declared" — which is
 * indistinguishable from the truth, is phrased with total confidence, and is *wrong*. It was
 * measured repeatedly on fresh worktrees: `locate Gateway` returned nothing while `show`
 * was reading `App\Models\Billing\Gateway` out of the same checkout seconds later, because
 * `show` falls through to Composer's map and `locate` had nothing to fall through to.
 *
 * A wrong "no" is worse than a slow "yes". Grep never lies with authority; a tool that does is
 * worse than the thing it replaced. So a negative is not returned until the bytes have been
 * read, and the cost — seconds, on the slowest path of the slowest command — is paid only when
 * the answer would otherwise have been "no".
 */
final readonly class DeclarationScan
{
    private const int FILE_BUDGET = 20000;

    public function __construct(
        private AutoloadMap $map,
        private SymbolLocator $locator,
    ) {}

    /**
     * Every declaration of a short name, found by reading the project's own source roots.
     *
     * @param  list<string>  $unparsed  filled in with files that could not be read, because
     *                                  "not found" and "could not look" are different answers
     * @return list<SymbolRecord>
     */
    public function byShortName(string $name, ?SymbolKind $kind, array &$unparsed = []): array
    {
        if (! $this->isIdentifier($name)) {
            return [];
        }

        $found = [];
        $budget = self::FILE_BUDGET;

        foreach ($this->map->ownRoots() as $root) {
            if (! is_dir($root)) {
                continue;
            }

            foreach ($this->phpFilesIn($root) as $file) {
                if (--$budget < 0) {
                    return $found;
                }

                if (! $this->mightDeclare($file->getPathname(), $name)) {
                    continue;
                }

                try {
                    $analysis = $this->locator->analyse($file->getPathname());
                } catch (ParseFailure $failure) {
                    $unparsed[] = sprintf('%s:%d  not parsed — %s', $failure->path, $failure->startLine, $failure->getMessage());

                    continue;
                }

                $path = $analysis->file->displayPath;

                foreach ($this->matchesIn($analysis->declarations, $name, $kind, $path, null) as $record) {
                    $found[] = $record;
                }

                foreach ($analysis->everyClassLike() as $classLike) {
                    foreach ($this->matchesIn($classLike->members, $name, $kind, $path, $classLike->fqn) as $record) {
                        $found[] = $record;
                    }
                }
            }
        }

        return $found;
    }

    /**
     * @param  list<Declaration>  $declarations
     * @return list<SymbolRecord>
     */
    private function matchesIn(array $declarations, string $name, ?SymbolKind $kind, string $path, ?string $owner): array
    {
        $found = [];

        foreach ($declarations as $declaration) {
            if ($kind instanceof SymbolKind && $declaration->kind !== $kind) {
                continue;
            }

            if (! $this->namesMatch($declaration, $name)) {
                continue;
            }

            $found[] = new SymbolRecord(
                path: $path,
                fqn: $declaration->fqn,
                name: $declaration->name,
                kind: $declaration->kind,
                owner: $owner,
                line: $declaration->spanStartLine,
                endLine: $declaration->endLine,
                column: $declaration->startColumn,
                signature: $declaration->signature,
                fullSignature: $declaration->fullSignature,
                modifiers: implode(' ', $declaration->modifiers),
                isPromoted: $declaration->isPromoted,
                hasBody: $declaration->hasBody,
                typeFqn: $declaration->typeFqn,
                inferredFqn: $declaration->inferredFqn,
                inferredReason: $declaration->inferredReason,
                inferredElements: $declaration->inferredElements,
            );
        }

        return $found;
    }

    /**
     * Class-likes, methods and functions are case-insensitive in PHP; constants and enum cases
     * are not. An index that folds all of them answers confidently and wrongly in both
     * directions, so the distinction is kept here too.
     */
    private function namesMatch(Declaration $declaration, string $name): bool
    {
        return match ($declaration->kind) {
            SymbolKind::Constant, SymbolKind::EnumCase, SymbolKind::Property => $declaration->name === $name,
            default => strcasecmp($declaration->name, $name) === 0,
        };
    }

    /**
     * A textual prefilter, not an answer: it decides which files are worth parsing, and
     * everything it lets through is confirmed against the parsed declaration. Its false
     * positives cost time and never correctness.
     */
    private function mightDeclare(string $path, string $name): bool
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            return false;
        }

        $quoted = preg_quote($name, '/');

        return preg_match('/(?:class|interface|trait|enum|function|const)\s+'.$quoted.'\b|\$'.$quoted.'\b|\bcase\s+'.$quoted.'\b/i', $contents) === 1;
    }

    private function isIdentifier(string $name): bool
    {
        return preg_match('/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/', $name) === 1;
    }

    /**
     * @return iterable<SplFileInfo>
     */
    private function phpFilesIn(string $root): iterable
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                yield $file;
            }
        }
    }
}

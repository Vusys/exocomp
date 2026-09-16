<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Analysis;

use Vusys\Exocomp\Source\SourceFile;
use Vusys\Exocomp\Symbol\SymbolKind;

/**
 * Everything one file declares and imports.
 */
final readonly class FileAnalysis
{
    /**
     * @param  list<Declaration>  $declarations  top-level declarations, in source order
     * @param  list<Import>  $imports
     * @param  list<string>  $namespaces  declared namespaces, in source order; "" is the global one
     * @param  list<AnonymousClass>  $anonymousClasses  present but unaddressable, reported rather than hidden
     * @param  list<string>  $referencedNames  every name the file mentions, for narrowing corpus-wide searches
     */
    public function __construct(
        public SourceFile $file,
        public array $declarations,
        public array $imports,
        public array $namespaces = [],
        public array $anonymousClasses = [],
        public array $referencedNames = [],
    ) {}

    /**
     * Class-like names are case-insensitive in PHP, so `app\models\order` and
     * `App\Models\Order` are the same class and both must find it.
     */
    public function classLike(string $fqn): ?Declaration
    {
        foreach ($this->declarations as $declaration) {
            if ($declaration->isClassLike() && strcasecmp($declaration->fqn, $fqn) === 0) {
                return $declaration;
            }
        }

        return null;
    }

    public function function(string $fqn): ?Declaration
    {
        foreach ($this->declarations as $declaration) {
            if ($declaration->kind === SymbolKind::FunctionDecl && strcasecmp($declaration->fqn, $fqn) === 0) {
                return $declaration;
            }
        }

        return null;
    }

    /**
     * @return list<Declaration>
     */
    public function classLikes(): array
    {
        return array_values(array_filter(
            $this->declarations,
            static fn (Declaration $declaration): bool => $declaration->isClassLike(),
        ));
    }

    /**
     * Every class-like the file holds, anonymous ones included.
     *
     * {@see self::classLikes()} answers "what can be addressed by name", which is the right
     * question when a name is what the caller has. This answers "what is in this file", which
     * is the right question when a *path* is what the caller has — and in a Laravel migration
     * the two differ completely, because the only class in the file is the anonymous one.
     *
     * @return list<Declaration>
     */
    public function everyClassLike(): array
    {
        $found = $this->classLikes();

        foreach ($this->anonymousClasses as $anonymous) {
            if ($anonymous->declaration instanceof Declaration) {
                $found[] = $anonymous->declaration;
            }
        }

        usort($found, static fn (Declaration $a, Declaration $b): int => $a->startOffset <=> $b->startOffset);

        return $found;
    }

    /**
     * Everything in the file that a bare member name could mean.
     *
     * A list rather than a single answer, because one file may declare several classes and a
     * path address says nothing about which one was meant. Handing back the first match would
     * be a guess, and the whole argument for this tool is that it does not make those — so an
     * ambiguous address is reported as ambiguous, with the candidates named.
     *
     * @return list<Declaration>
     */
    public function membersNamed(string $name, bool $isProperty): array
    {
        $found = [];

        foreach ($this->everyClassLike() as $classLike) {
            $member = $classLike->member($name, $isProperty);

            if ($member instanceof Declaration) {
                $found[] = $member;
            }
        }

        // A free function in the file answers to the same bare name, and in a helpers file it
        // is the only thing that could.
        if (! $isProperty) {
            foreach ($this->declarations as $declaration) {
                if ($declaration->kind === SymbolKind::FunctionDecl && strcasecmp($declaration->name, $name) === 0) {
                    $found[] = $declaration;
                }
            }
        }

        return $found;
    }

    /**
     * The innermost declaration whose source span covers a line.
     *
     * A member wins over the class that holds it: someone pointing at a line inside a method is
     * pointing at the method, and answering with the whole class would bury what they asked
     * about in everything they did not.
     */
    public function declarationSpanning(int $line): ?Declaration
    {
        $best = null;

        foreach ([...$this->declarations, ...$this->everyClassLike()] as $declaration) {
            foreach ([$declaration, ...$declaration->members] as $candidate) {
                if ($candidate->spanStartLine > $line || $candidate->endLine < $line) {
                    continue;
                }

                if (! $best instanceof Declaration || $this->narrowerThan($candidate, $best)) {
                    $best = $candidate;
                }
            }
        }

        return $best;
    }

    private function narrowerThan(Declaration $candidate, Declaration $incumbent): bool
    {
        return $candidate->endLine - $candidate->spanStartLine < $incumbent->endLine - $incumbent->spanStartLine;
    }
}

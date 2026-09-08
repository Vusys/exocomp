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
}

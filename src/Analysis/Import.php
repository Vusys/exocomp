<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Analysis;

/**
 * One resolved `use` statement. The alias is what the rest of the file actually writes, which
 * is the half grep cannot see: `use Acme\Http\Client as HttpClient` means every later
 * `HttpClient` is a reference to `Acme\Http\Client`, and no textual search for either name
 * finds both ends of that.
 */
final readonly class Import
{
    public function __construct(
        public string $fqn,
        public string $alias,
        public ImportKind $kind,
        public int $line,
        public int $column,
        public bool $isAliased,
    ) {}
}

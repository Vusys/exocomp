<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Framework\Laravel;

use Vusys\Exocomp\Symbol\Confidence;

/**
 * One entry in the container: an abstract name and whatever it was bound to.
 *
 * The abstract is often an interface and often a bare string. Both matter, and they are not
 * equally knowable — `bind(Reader::class, FileReader::class)` is checkable and
 * `bind('reader', …)` is a name the engine cannot verify against anything.
 */
final readonly class Binding
{
    public function __construct(
        public string $abstract,
        public ?string $concrete,
        public string $kind,
        public string $path,
        public int $line,
        public Confidence $confidence,
        public ?string $note = null,
        public ?string $registeredBy = null,
        public ?string $context = null,
    ) {}

    public function describe(): string
    {
        $arrow = $this->concrete ?? '?';

        return $this->context === null
            ? $this->abstract.'  →  '.$arrow
            : sprintf('%s  →  %s   when resolved for %s', $this->abstract, $arrow, $this->context);
    }
}

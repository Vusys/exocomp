<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Project;

use Vusys\Exocomp\Analysis\Declaration;
use Vusys\Exocomp\Analysis\FileAnalysis;

/**
 * A declaration together with the parsed file it was found in — the file is kept because every
 * command that shows a declaration also needs the file's bytes to print an exact span, and
 * re-reading it would be a second chance to disagree with what was just resolved.
 */
final readonly class LocatedDeclaration
{
    public function __construct(
        public FileAnalysis $analysis,
        public Declaration $declaration,
    ) {}
}

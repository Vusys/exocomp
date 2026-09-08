<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Framework;

use Vusys\Exocomp\Project\Project;

/**
 * A framework the tool knows how to read.
 *
 * The seam exists because the core is a general PHP engine and must stay one: every framework
 * answer is derived by parsing ordinary PHP, never by knowing anything the parser cannot see.
 * It is deliberately one method wide. There is one profile today, and a seam wider than its
 * single implementation would be a guess about the second one.
 */
interface Profile
{
    public function name(): string;

    /**
     * Whether this profile applies to a checkout, and what said so.
     */
    public function detect(Project $project): ?Detection;
}

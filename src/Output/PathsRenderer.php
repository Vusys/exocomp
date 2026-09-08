<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Output;

/**
 * Deduplicated file paths, one per line — the shape `xargs` wants.
 */
final class PathsRenderer implements Renderer
{
    public function render(Result $result): string
    {
        return implode("\n", $result->paths());
    }
}

<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Output;

interface Renderer
{
    /**
     * Renders a whole result. The structure is identical whether or not output is a terminal:
     * an agent's output is always captured, so format-switching on TTY detection would
     * silently hand the caller something other than what the documentation describes.
     */
    public function render(Result $result): string;
}

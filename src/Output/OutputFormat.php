<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Output;

use InvalidArgumentException;

enum OutputFormat: string
{
    case Text = 'text';
    case Json = 'json';
    case Paths = 'paths';

    public static function parse(string $value): self
    {
        return self::tryFrom($value) ?? throw new InvalidArgumentException(sprintf(
            'unknown --format "%s" (known: %s)',
            $value,
            implode(', ', array_column(self::cases(), 'value')),
        ));
    }

    public function renderer(): Renderer
    {
        return match ($this) {
            self::Text => new TextRenderer,
            self::Json => new JsonRenderer,
            self::Paths => new PathsRenderer,
        };
    }
}

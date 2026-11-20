<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Framework\Laravel;

use PhpParser\Error;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * A Blade view as the PHP it holds, at the lines it holds it on.
 *
 * Laravel compiles a view by running its compiler, and this tool runs nothing, so the PHP is
 * lifted out of the template by its syntax instead: `{{ }}` and `{!! !!}` echoes, `@php` blocks,
 * the arguments of directives (`@foreach` stays a loop, so its variable is typed after it), and
 * `@inject('name', Class)`, which becomes the assignment it is. A component tag, `<x-order-badge>`
 * or `<livewire:order-list>`, becomes a reference to the class Laravel's naming convention gives
 * it. Everything else is blanked to spaces, newlines kept, so every line and nearly every
 * column of the result is the view's own.
 *
 * Each fragment is kept only if it parses alone: one template this reading gets wrong costs
 * that fragment, never the rest of the view.
 */
final readonly class BladeSource
{
    private Parser $parser;

    public function __construct()
    {
        $this->parser = new ParserFactory()->createForNewestSupportedVersion();
    }

    public function toPhp(string $blade): string
    {
        $out = '';
        $length = strlen($blade);
        $i = 0;

        while ($i < $length) {
            $rest = substr($blade, $i, 20);

            // `{{-- … --}}` is a comment, and `@{{ … }}` an echo Blade leaves alone.
            if (str_starts_with($rest, '{{--')) {
                $end = strpos($blade, '--}}', $i + 4);
                $end = $end === false ? $length : $end + 4;
                $out .= $this->blank(substr($blade, $i, $end - $i));
                $i = $end;

                continue;
            }

            if (str_starts_with($rest, '@{{')) {
                $end = strpos($blade, '}}', $i + 3);
                $end = $end === false ? $length : $end + 2;
                $out .= $this->blank(substr($blade, $i, $end - $i));
                $i = $end;

                continue;
            }

            foreach ([['{!!', '!!}'], ['{{', '}}']] as [$open, $close]) {
                if (str_starts_with($rest, $open)) {
                    $end = strpos($blade, $close, $i + strlen($open));

                    if ($end !== false) {
                        $expression = substr($blade, $i + strlen($open), $end - $i - strlen($open));
                        $out .= str_repeat(' ', strlen($open)).$this->fragment($expression.';', $expression).str_repeat(' ', strlen($close) - 1);
                        $i = $end + strlen($close);

                        continue 2;
                    }
                }
            }

            if ($blade[$i] === '@' && preg_match('/\G@(\w+)/', $blade, $match, 0, $i) === 1) {
                [$consumed, $php] = $this->directive($blade, $i, $match[1]);

                if ($consumed > 0) {
                    $out .= $php;
                    $i += $consumed;

                    continue;
                }
            }

            if ($blade[$i] === '<' && preg_match('/\G<(x-[\w.\-:]+|livewire:[\w.\-]+)/', $blade, $match, 0, $i) === 1) {
                $end = $this->tagEnd($blade, $i);
                $out .= $this->component($match[1], substr($blade, $i, $end - $i));
                $i = $end;

                continue;
            }

            $out .= $blade[$i] === "\n" ? "\n" : ' ';
            $i++;
        }

        return '<?php '.$out;
    }

    /**
     * @return array{int, string} how much of the view the directive took, and its PHP
     */
    private function directive(string $blade, int $at, string $name): array
    {
        $word = strlen($name) + 1;
        $lower = strtolower($name);

        if ($lower === 'php') {
            // `@php(expr)` inline, or a block up to `@endphp`.
            $after = $at + $word;

            if (($blade[$after] ?? '') === '(') {
                $close = $this->matching($blade, $after);
                $inner = substr($blade, $after + 1, $close - $after - 1);

                return [$close + 1 - $at, str_repeat(' ', $word + 1).$this->fragment($inner.';', $inner).' '];
            }

            $end = strpos($blade, '@endphp', $after);
            $end = $end === false ? strlen($blade) : $end;
            $inner = substr($blade, $after, $end - $after);

            return [$end + 7 - $at, str_repeat(' ', $word).$this->fragment($inner, $inner).str_repeat(' ', 7)];
        }

        $open = $at + $word;

        while (($blade[$open] ?? '') === ' ') {
            $open++;
        }

        if (($blade[$open] ?? '') !== '(') {
            return [0, ''];
        }

        $close = $this->matching($blade, $open);
        $arguments = substr($blade, $open + 1, $close - $open - 1);
        $lead = str_repeat(' ', $open - $at);

        $php = match ($lower) {
            'foreach', 'forelse' => 'foreach ('.$arguments.') {}',
            'for' => 'for ('.$arguments.') {}',
            'while' => 'while ('.$arguments.') {}',
            'inject' => $this->inject($arguments),
            'livewire' => preg_match('/^\s*[\'"]([\w.\-]+)[\'"]/', $arguments, $match) === 1 ? $this->livewireClasses($match[1]).' ['.$arguments.'];' : '['.$arguments.'];',
            default => '['.$arguments.'];',
        };

        return [$close + 1 - $at, $lead.$this->fragment($php, '('.$arguments.')')];
    }

    /**
     * `@inject('gateway', 'App\Services\StripeGateway')`: the variable is that service.
     */
    private function inject(string $arguments): string
    {
        if (preg_match('/^\s*[\'"](\w+)[\'"]\s*,\s*(?:[\'"]\\\\?([\w\\\\]+)[\'"]|\\\\?([\w\\\\]+)::class)/', $arguments, $match) !== 1) {
            return '['.$arguments.'];';
        }

        $class = ($match[2] ?? '') !== '' ? $match[2] : ($match[3] ?? '');

        return sprintf('$%s = app(\\%s::class);', $match[1], ltrim($class, '\\'));
    }

    /**
     * A component tag: the class by convention, then its bound attributes as expressions.
     */
    private function component(string $name, string $tag): string
    {
        $php = '';

        if (str_starts_with($name, 'livewire:')) {
            $php = $this->livewireClasses(substr($name, 9));
        } elseif (! in_array($name, ['x-slot', 'x-dynamic-component'], true) && ! str_starts_with($name, 'x-slot:')) {
            $php = sprintf('\\App\\View\\Components\\%s::class;', $this->studlyPath(substr($name, 2)));
        }

        // `:order="$order"` is an expression, evaluated in the view.
        foreach (preg_match_all('/\s:[\w\-]+="([^"]*)"/', $tag, $matches) > 0 ? $matches[1] : [] as $expression) {
            $php .= ' '.$this->fragment($expression.';', '');
        }

        $blank = $this->blank($tag);
        $newlines = substr_count($tag, "\n");
        $php = rtrim($php);

        // Kept to the tag's own lines: the code on its first line, its newlines after.
        return strlen($php) <= strlen($blank) - $newlines
            ? $php.str_repeat(' ', max(strlen($blank) - strlen($php) - $newlines, 0)).str_repeat("\n", $newlines)
            : $php.str_repeat("\n", $newlines);
    }

    /**
     * Livewire 3 looks a component up in `App\Livewire`, Livewire 2 in `App\Http\Livewire`.
     */
    private function livewireClasses(string $name): string
    {
        $class = $this->studlyPath($name);

        return sprintf('\\App\\Livewire\\%1$s::class; \\App\\Http\\Livewire\\%1$s::class;', $class);
    }

    /**
     * `orders.status-badge` is `Orders\StatusBadge`.
     */
    private function studlyPath(string $name): string
    {
        return implode('\\', array_map(
            static fn (string $part): string => str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $part))),
            explode('.', str_replace('::', '.', $name)),
        ));
    }

    /**
     * The PHP, if it parses on its own; otherwise blanks the length of what it stood for.
     */
    private function fragment(string $php, string $original): string
    {
        try {
            $this->parser->parse('<?php '.$php);
        } catch (Error) {
            return $this->blank($original.' ');
        }

        return $php;
    }

    /**
     * Where a parenthesis opened at `$open` closes, skipping strings.
     */
    private function matching(string $text, int $open): int
    {
        $depth = 0;
        $quote = null;
        $length = strlen($text);

        for ($i = $open; $i < $length; $i++) {
            $char = $text[$i];

            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')' && --$depth === 0) {
                return $i;
            }
        }

        return $length - 1;
    }

    /**
     * The end of a tag opened at `$at`, past any `>` inside a quoted attribute.
     */
    private function tagEnd(string $text, int $at): int
    {
        $quote = null;
        $length = strlen($text);

        for ($i = $at; $i < $length; $i++) {
            $char = $text[$i];

            if ($quote !== null) {
                $quote = $char === $quote ? null : $quote;
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '>') {
                return $i + 1;
            }
        }

        return $length;
    }

    private function blank(string $text): string
    {
        return (string) preg_replace('/[^\n]/', ' ', $text);
    }
}

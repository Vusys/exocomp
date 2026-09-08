<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Symbol;

use InvalidArgumentException;

/**
 * A parsed symbol address: `App\Foo`, `App\Foo::bar`, `App\Foo::$bar`, `App\Foo::BAR`.
 *
 * Addressing is by name and never by cursor or byte offset (§3.1 principle 1): names survive
 * edits, offsets do not, and computing an offset requires reading the file first — at which
 * point the caller already has the content and no longer needs the tool.
 *
 * The member half is deliberately *not* classified here. `Foo::bar` is a legal address for a
 * method, a constant or an enum case, and only the declaration itself can say which; guessing
 * from casing would be exactly the confidently-wrong behaviour the design rules out.
 */
final readonly class SymbolRef implements \Stringable
{
    private function __construct(
        public string $owner,
        public ?string $member,
        public bool $memberIsProperty,
    ) {}

    public static function parse(string $address): self
    {
        $address = trim($address);

        if ($address === '') {
            throw new InvalidArgumentException('empty symbol address');
        }

        $parts = explode('::', $address);

        if (count($parts) > 2) {
            throw new InvalidArgumentException("\"{$address}\" has more than one \"::\"");
        }

        $owner = self::normaliseFqn($parts[0]);

        if ($owner === '') {
            throw new InvalidArgumentException("\"{$address}\" has no owning name");
        }

        if (! isset($parts[1])) {
            return new self($owner, null, false);
        }

        $member = trim($parts[1]);
        $isProperty = str_starts_with($member, '$');
        $member = ltrim($member, '$');

        if ($member === '') {
            throw new InvalidArgumentException("\"{$address}\" has no member name after \"::\"");
        }

        // `Foo::bar()` is how a call site is written, and an agent will paste it verbatim.
        if (str_ends_with($member, '()')) {
            $member = substr($member, 0, -2);
        }

        return new self($owner, $member, $isProperty);
    }

    public function hasMember(): bool
    {
        return $this->member !== null;
    }

    public function __toString(): string
    {
        if ($this->member === null) {
            return $this->owner;
        }

        return $this->owner.'::'.($this->memberIsProperty ? '$' : '').$this->member;
    }

    /**
     * Strips a leading backslash and collapses any doubled separators, so the shell-escaped
     * `App\\Models\\Order` and the source-form `\App\Models\Order` address the same symbol.
     */
    private static function normaliseFqn(string $name): string
    {
        $name = str_replace('\\\\', '\\', trim($name));

        return trim($name, '\\');
    }
}

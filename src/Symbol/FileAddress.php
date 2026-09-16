<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Symbol;

use InvalidArgumentException;
use Stringable;

/**
 * An address that names a file rather than a symbol: `path/to/File.php::method`.
 *
 * Addressing by name is the rule and stays the rule — names survive edits and offsets do not.
 * This is the exception the evidence forced, and it is narrow in a specific way: the *file* is
 * positional, the *member* is still a name. Nothing here depends on a line number, so the
 * address keeps working after the file is edited above it.
 *
 * Two things made it necessary. The first is that a caller very often already has the path and
 * not the namespace — it came out of a diff, a stack trace, a test failure, a previous answer —
 * and making it convert one to the other first means a second lookup before the real question,
 * which is exactly the friction that sends someone back to `grep -n 'function x' -A 30 <file>`.
 * The second is harder: a Laravel migration is an anonymous class, so it has no
 * fully-qualified name at all, and without a file address there is no way to ask about it.
 */
final readonly class FileAddress implements Stringable
{
    private function __construct(
        public string $path,
        public ?string $member,
        public bool $memberIsProperty,
    ) {}

    /**
     * The address, if it names a file that is there — otherwise null, so the caller can fall
     * back to reading it as a symbol.
     *
     * Existence on disk is what decides, rather than the presence of a slash or a `.php`
     * suffix. A namespace separator is a backslash and a path separator is a forward slash, so
     * the two forms are hard to confuse in principle; in practice an agent will write
     * `App/Models/Order` sooner or later, and resolving that as a missing file rather than as
     * the class it obviously means would be the tool being pedantic at the caller's expense.
     *
     * *Where* to look is the caller's business and not this class's. A path is written relative
     * to the project far more often than to whatever directory the process happens to be in —
     * it came out of a diff, a stack trace or a previous answer, all of which report
     * project-relative paths — so the resolver is passed in rather than assumed.
     *
     * @param  callable(string): ?string  $resolve  a written path to an absolute one, or null
     */
    public static function parse(string $address, callable $resolve): ?self
    {
        $address = trim($address);

        if ($address === '') {
            return null;
        }

        $parts = explode('::', $address);

        if (count($parts) > 2) {
            throw new InvalidArgumentException("\"{$address}\" has more than one \"::\"");
        }

        $written = trim($parts[0]);

        if ($written === '') {
            return null;
        }

        $path = $resolve($written);

        if ($path === null) {
            return null;
        }

        if (! isset($parts[1])) {
            return new self($path, null, false);
        }

        $member = trim($parts[1]);
        $isProperty = str_starts_with($member, '$');
        $member = ltrim($member, '$');

        // `Foo::bar()` is how a call site is written, and an agent will paste it verbatim.
        if (str_ends_with($member, '()')) {
            $member = mb_substr($member, 0, -2);
        }

        if ($member === '') {
            throw new InvalidArgumentException("\"{$address}\" has no member name after \"::\"");
        }

        return new self($path, $member, $isProperty);
    }

    public function hasMember(): bool
    {
        return $this->member !== null;
    }

    public function __toString(): string
    {
        if ($this->member === null) {
            return $this->path;
        }

        return $this->path.'::'.($this->memberIsProperty ? '$' : '').$this->member;
    }
}

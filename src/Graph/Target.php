<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Graph;

use InvalidArgumentException;
use Stringable;
use Vusys\Exocomp\Symbol\Identifier;

/**
 * What counts as arriving.
 *
 * The question people actually ask is rarely about one method. "Does this endpoint reach a
 * write on Order" means any write on Order, and "does anything here call `delete`" means any
 * class's `delete` — so a destination is a shape rather than an address. Three forms, all of
 * them literal:
 *
 * - `App\Models\Order::save` — that member of that class
 * - `App\Models\Order` — anything on that class
 * - `::save` — that member on any class
 *
 * `*` is allowed in either half. Nothing here is inferred: a target either matches the name of
 * a node the walk arrived at or it does not, and the confidence attached to the answer comes
 * entirely from the edges walked to get there.
 */
final readonly class Target implements Stringable
{
    private function __construct(
        private string $spelling,
        private string $owner,
        private ?string $member,
    ) {}

    public static function parse(string $spelling): self
    {
        $trimmed = trim($spelling);

        if ($trimmed === '') {
            throw new InvalidArgumentException('empty destination');
        }

        $parts = explode('::', $trimmed);

        if (count($parts) > 2) {
            throw new InvalidArgumentException(sprintf('"%s" has more than one "::"', $trimmed));
        }

        $owner = trim(str_replace('\\\\', '\\', $parts[0]), " \\\t\n");
        $member = isset($parts[1]) ? trim($parts[1], " \t\n()$") : null;

        if ($owner === '' && ($member === null || $member === '')) {
            throw new InvalidArgumentException(sprintf('"%s" names neither a class nor a member', $trimmed));
        }

        return new self($trimmed, $owner === '' ? '*' : $owner, $member === '' ? null : $member);
    }

    /**
     * Whether a node — `Class::method`, or a bare function name — is the thing being looked for.
     */
    public function matches(string $node): bool
    {
        $parts = explode('::', $node);
        $owner = $parts[0];
        $member = $parts[1] ?? null;

        if (! self::glob($this->owner, $owner)) {
            return false;
        }

        // A class-only destination is satisfied by any member of it, including the class's own
        // declaration reached as a bare name.
        if ($this->member === null) {
            return true;
        }

        return $member !== null && self::glob($this->member, $member);
    }

    public function __toString(): string
    {
        return $this->spelling;
    }

    /**
     * Glob comparison, folded the way PHP folds identifiers.
     */
    public static function glob(string $pattern, string $subject): bool
    {
        $pattern = Identifier::fold(trim($pattern, '\\'));
        $subject = Identifier::fold(trim($subject, '\\'));

        if (! str_contains($pattern, '*')) {
            return $pattern === $subject;
        }

        $regex = '/^'.str_replace('\*', '.*', preg_quote($pattern, '/')).'$/';

        return preg_match($regex, $subject) === 1;
    }
}

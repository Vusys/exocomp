<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Inference;

use Vusys\Exocomp\Symbol\Confidence;

/**
 * A type, and how much the tool is prepared to stand behind it.
 *
 * The confidence is not decoration. It is what makes partial inference shippable: coverage
 * becomes a number that can be reported and improved, instead of a cliff that has to be
 * reached before anything is trustworthy. And on the write side it is the difference between a
 * reference that may be rewritten and one that must be handed back for a human to judge.
 */
final readonly class ResolvedType
{
    /**
     * @param  ?string  $wrapper  for a framework object that stands for models — a query builder,
     *                            a factory, a collection — which of those it is
     *                            ({@see EloquentReturns}); the model it stands for is `$model`
     * @param  array<string, string>  $elements  for an array, the class a docblock gives its
     *                                           element at each key, `*` for any key
     * @param  ?self  $invokes  for a first-class callable, what calling it returns
     * @param  ?string  $callable  for a first-class callable, the declaration it calls
     */
    private function __construct(
        public ?string $fqn,
        public Confidence $confidence,
        public string $reason,
        public ?string $wrapper = null,
        public ?string $model = null,
        public array $elements = [],
        public ?self $invokes = null,
        public ?string $callable = null,
    ) {}

    /**
     * A first-class callable: a `Closure`, with what calling it returns and, when it is known,
     * the declaration it stands for.
     */
    public static function closure(?self $returns, ?string $target): self
    {
        return new self('Closure', Confidence::Resolved, 'a first-class callable', invokes: $returns, callable: $target);
    }

    /**
     * An array whose elements a docblock names. The array itself is no class, so this is not a
     * known type: only what is read out of it is.
     *
     * @param  array<string, string>  $elements
     */
    public static function arrayOf(array $elements, string $tag): self
    {
        return new self(null, Confidence::Textual, sprintf('an array documented by %s', $tag), elements: $elements);
    }

    /**
     * The element at $key, or with no key (a `foreach`, a computed index) the one class every
     * element is documented as.
     */
    public function element(?string $key): ?self
    {
        $fqn = ($key === null ? null : $this->elements[$key] ?? null) ?? $this->elements['*'] ?? null;

        if ($fqn === null && $key === null && count(array_unique(array_map(strtolower(...), $this->elements))) === 1) {
            $fqn = array_values($this->elements)[0] ?? null;
        }

        return $fqn === null ? null : self::inferred($fqn, 'an element of '.$this->reason);
    }

    /**
     * A builder, factory or collection of a known model, which Eloquent's API turns back into
     * that model. The object itself is a framework class the index does not hold, so this is
     * what lets a chain like `Order::query()->where(...)->first()` end at `Order`.
     */
    public static function wrapping(string $fqn, string $wrapper, string $model, string $reason): self
    {
        return new self($fqn, Confidence::Inferred, $reason, $wrapper, $model);
    }

    /**
     * Established from a declaration: a parameter type, a property type, a return type, `$this`.
     * In modern typed PHP this covers the overwhelming majority of receivers, because the types
     * are simply written down.
     */
    public static function declared(string $fqn): self
    {
        return new self($fqn, Confidence::Resolved, 'declared type');
    }

    public static function resolvedBy(string $fqn, string $reason): self
    {
        return new self($fqn, Confidence::Resolved, $reason);
    }

    /**
     * A type established at a confidence the caller worked out — used where a chain of evidence
     * decides the strength rather than a single kind of declaration.
     */
    public static function of(string $fqn, Confidence $confidence, string $reason): self
    {
        return new self($fqn, $confidence, $reason);
    }

    /**
     * Established from something that was written down but is not binding — a docblock, most
     * often. Worth reporting, never worth rewriting on.
     */
    public static function inferred(string $fqn, string $reason): self
    {
        return new self($fqn, Confidence::Inferred, $reason);
    }

    /**
     * Not established at all. This is a first-class answer rather than a failure: the tests
     * that matter most assert that the tool reaches it, because a receiver it cannot know and
     * claims to know is worse than one it admits to.
     */
    public static function unknown(string $reason): self
    {
        return new self(null, Confidence::Textual, $reason);
    }

    public function isKnown(): bool
    {
        return $this->fqn !== null;
    }
}

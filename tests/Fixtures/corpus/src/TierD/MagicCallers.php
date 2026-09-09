<?php

declare(strict_types=1);

namespace Fixtures\TierD;

/**
 * Call sites for every magic shape. The expectations live in the tests; what matters here is
 * that the two halves of each pair are written identically, so that any difference in the
 * answer comes from the receiver rather than from the call.
 */
final class MagicCallers
{
    public function __construct(
        private readonly Bag $bag,
        private readonly AnnotatedBag $annotated,
        private readonly MixinBag $mixin,
        private readonly Forwarder $forwarder,
        private readonly Computed $computed,
        private readonly Invokable $invokable,
        private readonly BorrowedClaims $borrowed,
    ) {}

    /** Nothing is written down. This must be textual. */
    public function throughBag(): mixed
    {
        return $this->bag->fetch('key');
    }

    /** The same call, against a class that declares the method. Inferred, never resolved. */
    public function throughAnnotation(): mixed
    {
        return $this->annotated->fetch('key');
    }

    /** A `@method static` declaration, called statically. */
    public function throughStaticAnnotation(): mixed
    {
        return AnnotatedBag::make();
    }

    /** An annotated property. The type is a claim, so the call on it is too. */
    public function throughAnnotatedProperty(): bool
    {
        return $this->annotated->current->send('hello');
    }

    /** The same read against the unannotated twin. Textual. */
    public function throughBareProperty(): mixed
    {
        return $this->bag->current;
    }

    /** `@mixin` redirects the lookup to a named class. */
    public function throughMixin(): bool
    {
        return $this->mixin->send('hello');
    }

    /** A single-statement forwarder to a typed property. */
    public function throughForwarder(): bool
    {
        return $this->forwarder->send('hello');
    }

    /** The forwarder's target has no such member, so knowing where the name goes proves nothing. */
    public function throughForwarderToNothing(): mixed
    {
        return $this->forwarder->noSuchMethod();
    }

    /** A mixin into a class that only annotates the member. Still a claim, with nowhere to jump. */
    public function throughBorrowedClaim(): mixed
    {
        return $this->borrowed->fetch('key');
    }

    /** A `__call` that computes its target. Unknowable, and it must say so. */
    public function throughComputed(): mixed
    {
        return $this->computed->getSomething();
    }

    /** An invokable called as a function. The receiver's type is declared, so this is not dynamic. */
    public function throughInvoke(): bool
    {
        return ($this->invokable)('hello');
    }

    /** No type anywhere, so even `__invoke` is unknowable. */
    public function throughUntypedInvoke($anything): mixed
    {
        return $anything('hello');
    }
}

/**
 * Callable as a function, because it declares `__invoke`. A call site writes no method name at
 * all, which is why a search for the name finds nothing and the tool has to supply it.
 */
final class Invokable
{
    public function __invoke(string $message): bool
    {
        return true;
    }
}

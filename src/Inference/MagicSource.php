<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Inference;

/**
 * One kind of evidence for a member nothing declares.
 *
 * The seam exists because the evidence comes from unrelated places — a docblock in the class, a
 * naming convention the framework implements, the body of a `__call`, a registration call in a
 * service provider — and each of them has a different reason to be believed. Keeping them
 * separate is what lets the answer say *which* one applied, which is the only thing that makes
 * an inferred answer actionable.
 *
 * A source that cannot answer returns null rather than guessing, and the next one is asked.
 */
interface MagicSource
{
    public function method(string $ownerFqn, string $name): ?MagicMember;

    public function property(string $ownerFqn, string $name): ?MagicMember;

    /**
     * The other names a call site may use for a member declared under this one.
     *
     * Needed for the inverse question. Finding every reference to `scopeActive()` means finding
     * every `->active()`, and nothing in a file that only writes `active()` mentions the
     * declared name at all — so without this, a rename would search for a string that is not
     * there and report full confidence in an empty answer.
     *
     * @return list<string>
     */
    public function spellings(string $ownerFqn, string $declaredName): array;
}

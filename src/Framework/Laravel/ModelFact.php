<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Framework\Laravel;

use Vusys\Exocomp\Symbol\Confidence;

/**
 * One thing known about a model, with where it was found and how well it is known.
 *
 * A model's facts come from several places — its own body, a parent's, a trait's, and the
 * framework's conventions — and which one answered matters. `$table` declared on the model is
 * a fact; a table name derived from the class name is a convention that happens to be right
 * most of the time. Flattening the two into one string is how a tool starts being believed
 * about things it guessed.
 */
final readonly class ModelFact
{
    public function __construct(
        public string $group,
        public string $name,
        public ?string $value,
        public Confidence $confidence,
        public string $path,
        public ?int $line = null,
        public ?string $note = null,
        public ?string $declaredBy = null,
    ) {}
}

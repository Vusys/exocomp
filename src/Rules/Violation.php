<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Rules;

use Vusys\Exocomp\Query\QueryMatch;

/**
 * One rule matching in one place.
 *
 * The fingerprint deliberately leaves out the line number. A baseline keyed by line turns every
 * unrelated edit above a waived violation into a false failure, and a guard that cries wolf
 * gets switched off — which is the same way a lint rule nobody trusts ends up in the ignore
 * list. Keyed by rule, file, enclosing declaration and the matched code, it survives the file
 * moving around it and still notices a genuinely new occurrence.
 */
final readonly class Violation
{
    public function __construct(
        public string $ruleId,
        public string $message,
        public string $path,
        public int $line,
        public int $column,
        public string $context,
        public string $snippet,
    ) {}

    public static function from(Rule $rule, QueryMatch $match): self
    {
        return new self(
            ruleId: $rule->id,
            message: $rule->message,
            path: $match->file->displayPath,
            line: $match->line(),
            column: $match->column(),
            context: $match->context(),
            snippet: $match->snippet(120),
        );
    }

    public function fingerprint(): string
    {
        return $this->ruleId."\0".$this->path."\0".$this->context."\0".$this->snippet;
    }
}

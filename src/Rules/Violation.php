<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\PrettyPrinter\Standard;
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
        public ?string $shape = null,
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
            shape: self::shapeOf($match->node),
        );
    }

    /**
     * The matched code as the pretty-printer writes it, on one line: the same for any layout of
     * the same code, so a formatter run does not turn a baselined violation into a new one.
     */
    public static function shapeOf(Node $node): string
    {
        $printer = new Standard;
        $printed = $node instanceof Expr ? $printer->prettyPrintExpr($node) : ($node instanceof Stmt ? $printer->prettyPrint([$node]) : '');

        return trim((string) preg_replace('/\s+/', ' ', $printed));
    }

    public function fingerprint(): string
    {
        return $this->ruleId."\0".$this->path."\0".$this->context."\0".($this->shape ?? $this->snippet);
    }

    /**
     * The fingerprint a baseline written before shapes were recorded holds: the code as written.
     */
    public function legacyFingerprint(): string
    {
        return $this->ruleId."\0".$this->path."\0".$this->context."\0".$this->snippet;
    }
}

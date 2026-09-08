<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Query;

use PhpParser\Node;
use Vusys\Exocomp\Index\SymbolGraph;
use Vusys\Exocomp\Inference\ResolvedType;
use Vusys\Exocomp\Source\SourceFile;

/**
 * One place a pattern matched, with everything a predicate might need to judge it.
 *
 * The enclosing declarations are carried because most of the useful questions are about
 * context rather than about the matched expression: "this call, but only inside a repository",
 * "this call, but only where the method is public". Each of those was a piped `grep -v` in the
 * measured history, applied by eye to output that had already lost the context.
 */
final readonly class QueryMatch
{
    public function __construct(
        public SourceFile $file,
        public Node $node,
        public Captures $captures,
        public ?Node\Stmt\ClassLike $class,
        public ?string $classFqn,
        public ?Node\FunctionLike $function,
        public ?string $functionName,
        public string $namespace,
        public ?ResolvedType $receiverType = null,
        public ?SymbolGraph $graph = null,
    ) {}

    public function line(): int
    {
        return $this->node->getStartLine();
    }

    public function column(): int
    {
        $start = $this->node->getStartFilePos();

        return $start < 0 ? 1 : $this->file->columnAt($start);
    }

    /**
     * The matched source, flattened to one line so a result set stays one record per line.
     */
    public function snippet(int $budget = 80): string
    {
        $start = $this->node->getStartFilePos();
        $end = $this->node->getEndFilePos();
        $source = $start < 0 || $end < $start ? '' : $this->file->slice($start, $end);
        $flat = trim((string) preg_replace('/\s+/', ' ', $source));

        return strlen($flat) <= $budget ? $flat : mb_strcut($flat, 0, $budget, 'UTF-8').'…';
    }

    /**
     * The most specific name that contains this match — what a reader needs to know where they
     * are without opening the file.
     */
    public function context(): string
    {
        if ($this->classFqn !== null && $this->functionName !== null) {
            return $this->classFqn.'::'.$this->functionName;
        }

        return $this->classFqn ?? $this->functionName ?? $this->namespace;
    }
}

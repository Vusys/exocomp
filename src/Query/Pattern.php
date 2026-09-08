<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Query;

use InvalidArgumentException;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Stmt\Expression;
use PhpParser\ParserFactory;

/**
 * A search pattern written as PHP, with metavariables.
 *
 * The syntax is the code being looked for, which is the whole reason to choose it: a caller
 * already writes PHP fluently, so there is nothing to learn and nothing to translate. This is
 * what the 2,170 measured multi-stage grep pipes were straining towards — they were already
 * queries, expressed in the wrong language and with no structure to hold on to.
 *
 * Three constructs are not literal:
 *
 * - `$UPPERCASE` matches any single node and binds it under that name.
 * - `...` in an argument or parameter list matches any number of them. It is real PHP — the
 *   first-class callable syntax — so the pattern still parses.
 * - `{}` as a body matches any body, for the same reason: it parses, and a pattern that has to
 *   parse is a pattern the caller can check by eye.
 */
final readonly class Pattern
{
    private function __construct(
        public string $source,
        public Node $root,
    ) {}

    public static function parse(string $pattern): self
    {
        $pattern = trim($pattern);

        if ($pattern === '') {
            throw new InvalidArgumentException('empty pattern');
        }

        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $problem = null;

        // An expression first, since almost every pattern is one; then the raw form, which is
        // what a declaration like `function $M(...) {}` needs.
        foreach ([$pattern.';', $pattern] as $candidate) {
            try {
                $statements = $parser->parse('<?php '.$candidate);
            } catch (Error $error) {
                $problem ??= $error->getRawMessage();

                continue;
            }

            if ($statements === null || $statements === []) {
                continue;
            }

            if (count($statements) > 1) {
                throw new InvalidArgumentException('a pattern must be a single expression or declaration');
            }

            $root = $statements[0];

            return new self($pattern, $root instanceof Expression ? $root->expr : $root);
        }

        throw new InvalidArgumentException(sprintf('"%s" is not parseable PHP: %s', $pattern, $problem ?? 'unknown'));
    }

    /**
     * Two spellings, because PHP allows a variable in some positions and only an identifier in
     * others.
     *
     * `$UPPERCASE` covers every expression position. Real code overwhelmingly uses lower or
     * camel case for variables, so the convention costs nothing and needs no sigil of its own.
     *
     * `_UPPERCASE` covers the identifier positions a variable cannot reach — a method name, a
     * declaration name, a constant. The leading underscore is what makes it safe: an all-caps
     * identifier alone would swallow every constant reference in PHP, since `Foo::MAX_DEPTH`
     * would read as a wildcard, and a pattern that silently matched more than it said would be
     * the confidently-wrong failure in query form.
     */
    public static function metavariableName(Node $node): ?string
    {
        if ($node instanceof Node\Expr\Variable && is_string($node->name)) {
            return preg_match('/^[A-Z_][A-Z0-9_]*$/', $node->name) === 1 ? $node->name : null;
        }

        $identifier = match (true) {
            $node instanceof Node\Identifier => $node->toString(),
            $node instanceof Node\Name && count($node->getParts()) === 1 => $node->getLast(),
            default => null,
        };

        if ($identifier === null || preg_match('/^_[A-Z][A-Z0-9_]*$/', $identifier) !== 1) {
            return null;
        }

        return substr($identifier, 1);
    }

    public static function isMetavariable(Node $node): bool
    {
        return self::metavariableName($node) !== null;
    }

    /**
     * The names any file matching this pattern must contain.
     *
     * A pattern that names a method cannot match where that method is never mentioned, so this
     * is what lets the index rule out almost the whole corpus before a parser starts. It is
     * deliberately conservative: only names written literally count, and a pattern with none
     * (`function _M() {}`) returns nothing and gets the full sweep it actually needs.
     *
     * @return list<string>
     */
    public function requiredNames(): array
    {
        $names = [];
        $this->walkNames($this->root, $names);

        return array_values(array_unique($names));
    }

    /**
     * @param  list<string>  $names
     */
    private function walkNames(Node $node, array &$names): void
    {
        if (! self::isMetavariable($node)) {
            $literal = match (true) {
                $node instanceof Node\Identifier => $node->toString(),
                $node instanceof Node\Name => $node->getLast(),
                default => null,
            };

            if ($literal !== null && $literal !== '') {
                $names[] = $literal;
            }
        }

        foreach ($node->getSubNodeNames() as $subNode) {
            $value = $node->{$subNode};

            foreach (is_array($value) ? $value : [$value] as $child) {
                if ($child instanceof Node) {
                    $this->walkNames($child, $names);
                }
            }
        }
    }

    /**
     * @return list<string>
     */
    public function metavariables(): array
    {
        $names = [];
        $this->walk($this->root, $names);

        return array_values(array_unique($names));
    }

    /**
     * @param  list<string>  $names
     */
    private function walk(Node $node, array &$names): void
    {
        $name = self::metavariableName($node);

        if ($name !== null) {
            $names[] = $name;
        }

        foreach ($node->getSubNodeNames() as $subNode) {
            $value = $node->{$subNode};

            foreach (is_array($value) ? $value : [$value] as $child) {
                if ($child instanceof Node) {
                    $this->walk($child, $names);
                }
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Framework\Laravel;

use PhpParser\Node;
use PhpParser\NodeFinder;
use Vusys\Exocomp\Analysis\Analyser;
use Vusys\Exocomp\Analysis\LiteralValue;
use Vusys\Exocomp\Source\ParseFailure;
use Vusys\Exocomp\Source\SourceFile;
use Vusys\Exocomp\Symbol\Confidence;

/**
 * Where a config key is defined, and where it is read.
 *
 * 307 measured searches were after one of those two halves, and neither is a question grep
 * answers well. A definition is a nested array key in a file returning one big literal, so
 * `grep 'timeout'` finds every `timeout` in every config file and none of them tells you the
 * dotted key. A read is `config('services.stripe.key')`, which grep does find — along with the
 * same string in a comment, a docblock and a test fixture.
 *
 * Values that are not literals are the norm rather than the exception here: half of a config
 * file is `env(…)` calls. Those are reported as what they are — an environment lookup with its
 * default — because "the value is whatever the environment says" is the true answer, and
 * printing the default as though it were the value would be wrong on every deployed machine.
 */
final class ConfigReader
{
    /** @var list<ConfigEntry> */
    private array $definitions = [];

    /** @var list<string> */
    private array $notes = [];

    public function __construct(private readonly Analyser $analyser = new Analyser) {}

    /**
     * @param  callable(string): string  $displayPath
     */
    public function readDirectory(string $directory, callable $displayPath): self
    {
        if (! is_dir($directory)) {
            $this->notes[] = sprintf('%s is not there, so no config keys could be read', $directory);

            return $this;
        }

        $files = glob(rtrim($directory, '/').'/*.php');

        if ($files === false) {
            return $this;
        }

        sort($files);

        foreach ($files as $absolute) {
            $this->readFile($absolute, $displayPath($absolute), basename($absolute, '.php'));
        }

        return $this;
    }

    /**
     * @return list<ConfigEntry>
     */
    public function definitions(): array
    {
        return $this->definitions;
    }

    /**
     * Definitions of a key, of its ancestors, and of everything beneath it.
     *
     * All three are answers to "where is this defined". A key that is not itself a leaf is
     * defined by the subtree under it, and a leaf that is missing may still be present as a
     * default on the array above — reporting only exact matches would call both of those
     * "not found".
     *
     * @return list<ConfigEntry>
     */
    public function matching(string $key): array
    {
        return array_values(array_filter(
            $this->definitions,
            static fn (ConfigEntry $entry): bool => $entry->key === $key
                || str_starts_with($entry->key, $key.'.')
                || str_starts_with($key, $entry->key.'.'),
        ));
    }

    /**
     * @return list<string>
     */
    public function notes(): array
    {
        return $this->notes;
    }

    private function readFile(string $absolute, string $path, string $root): void
    {
        try {
            [, $statements] = $this->analyser->analyseWithAst(SourceFile::fromPath($absolute, $path));
        } catch (ParseFailure $failure) {
            $this->notes[] = sprintf('%s:%d could not be parsed: %s', $path, $failure->startLine, $failure->getMessage());

            return;
        }

        foreach (new NodeFinder()->findInstanceOf($statements, Node\Stmt\Return_::class) as $return) {
            $array = $this->returnedArray($return, $statements);

            if ($array instanceof Node\Expr\Array_) {
                $this->walk($array, $root, $path);

                return;
            }
        }

        $this->notes[] = sprintf('%s does not return an array this reader could follow, so its keys are missing', $path);
    }

    /**
     * The array a config file returns.
     *
     * Plenty of real config files build the array in a variable and return that — usually so a
     * comment block can sit above each section. Handling only `return [ … ]` reports every key
     * in such a file as undefined, which is a confident answer about a file sitting right there.
     *
     * @param  array<int, Node>  $statements
     */
    private function returnedArray(Node\Stmt\Return_ $return, array $statements): ?Node\Expr\Array_
    {
        if ($return->expr instanceof Node\Expr\Array_) {
            return $return->expr;
        }

        if (! $return->expr instanceof Node\Expr\Variable || ! is_string($return->expr->name)) {
            return null;
        }

        $name = $return->expr->name;
        $found = null;

        // The last assignment wins, since a later one supersedes an earlier one.
        foreach (new NodeFinder()->findInstanceOf($statements, Node\Expr\Assign::class) as $assignment) {
            if ($assignment->var instanceof Node\Expr\Variable
                && $assignment->var->name === $name
                && $assignment->expr instanceof Node\Expr\Array_) {
                $found = $assignment->expr;
            }
        }

        return $found;
    }

    private function walk(Node\Expr\Array_ $array, string $prefix, string $path): void
    {
        foreach ($array->items as $item) {
            if (! $item->key instanceof Node\Expr) {
                continue;
            }

            $key = LiteralValue::scalar($item->key);

            if (! is_string($key) && ! is_int($key)) {
                continue;
            }

            $dotted = $prefix.'.'.$key;

            if ($item->value instanceof Node\Expr\Array_) {
                // The array itself is a key too: `config('database.connections')` is a real
                // read, and reporting only its leaves would call it undefined.
                $this->definitions[] = new ConfigEntry(
                    $dotted,
                    sprintf('array of %d', count($item->value->items)),
                    $path,
                    $item->getStartLine(),
                    Confidence::Resolved,
                    'definition',
                );

                $this->walk($item->value, $dotted, $path);

                continue;
            }

            [$value, $note, $confidence] = $this->describe($item->value);

            $this->definitions[] = new ConfigEntry($dotted, $value, $path, $item->getStartLine(), $confidence, 'definition', $note);
        }
    }

    /**
     * @return array{?string, ?string, Confidence}
     */
    private function describe(Node\Expr $expression): array
    {
        $environment = $this->environmentLookup($expression);

        if ($environment !== null) {
            return $environment;
        }

        $value = LiteralValue::className($expression) ?? LiteralValue::scalar($expression);

        if ($value === null) {
            return [null, 'the value is computed', Confidence::Textual];
        }

        return [is_bool($value) ? ($value ? 'true' : 'false') : (string) $value, null, Confidence::Resolved];
    }

    /**
     * @return ?array{?string, ?string, Confidence}
     */
    private function environmentLookup(Node\Expr $expression): ?array
    {
        if (! $expression instanceof Node\Expr\FuncCall
            || ! $expression->name instanceof Node\Name
            || ! Analyser::isGlobalFunction($expression->name, 'env')) {
            return null;
        }

        $arguments = $expression->getArgs();
        $variable = $arguments === [] ? null : LiteralValue::scalar($arguments[0]->value);
        $default = isset($arguments[1]) ? LiteralValue::scalar($arguments[1]->value) : null;

        return [
            sprintf('env(%s)', is_string($variable) ? $variable : '?'),
            $default === null
                ? 'read from the environment; no default here'
                : sprintf('read from the environment, defaulting to %s', is_bool($default) ? ($default ? 'true' : 'false') : (string) $default),
            Confidence::Inferred,
        ];
    }

    /**
     * Every read of this key, or of a key it contains or is contained by.
     *
     * The files are prefiltered on the key's first segment before anything is parsed. That is a
     * substring test on bytes already on disk, and it cannot exclude a file that would have
     * matched, because a literal read of `mail.mailers.smtp` contains `mail` — so it is a
     * narrowing rather than a heuristic. Everything surviving it is parsed, so a match is an
     * actual call and not the same string sitting in a comment.
     *
     * @param  list<string>  $paths  display paths, relative to the project root
     * @param  callable(string): string  $absolute
     * @return list<ConfigEntry>
     */
    public function usages(string $key, array $paths, callable $absolute): array
    {
        $root = strtok($key, '.');
        $segment = is_string($root) ? $root : $key;
        $found = [];

        foreach ($paths as $path) {
            $file = $absolute($path);
            $contents = is_file($file) ? file_get_contents($file) : false;

            if ($contents === false || ! $this->mightRead($contents, $segment)) {
                continue;
            }

            try {
                [, $statements] = $this->analyser->analyseWithAst(SourceFile::fromString($contents, $path));
            } catch (ParseFailure) {
                $this->notes[] = sprintf('%s could not be parsed, so any read in it is missing', $path);

                continue;
            }

            foreach ($this->readsIn($statements, $key, $path) as $entry) {
                $found[] = $entry;
            }
        }

        return $found;
    }

    /**
     * A key is always read as a string literal, so its first segment sits immediately after a
     * quote and is followed either by a dot or by the closing quote. Testing for that shape
     * rather than for the bare segment is what keeps the scan from parsing the whole corpus for
     * a key beginning `ai` — measured at fourteen seconds, because `ai` is a substring of half
     * the words in a large application, against under two for the shape.
     *
     * It is still a narrowing rather than a heuristic. Every read of this key or of a key above
     * it — including `config("ai.{$name}")`, whose key is not a literal and which is reported
     * as computed rather than dropped — begins with that shape, so no file that would have
     * matched is excluded.
     */
    private function mightRead(string $contents, string $segment): bool
    {
        return preg_match('/[\'"]'.preg_quote($segment, '/').'(?:\.|[\'"])/', $contents) === 1;
    }

    /**
     * @param  array<int, Node>  $statements
     * @return list<ConfigEntry>
     */
    private function readsIn(array $statements, string $key, string $path): array
    {
        $found = [];

        foreach (new NodeFinder()->find($statements, static fn (Node $node): bool => $node instanceof Node\Expr\FuncCall || $node instanceof Node\Expr\StaticCall) as $node) {
            $call = $this->configCall($node);

            if ($call === null) {
                continue;
            }

            [$kind, $arguments] = $call;
            $literal = $arguments === [] ? null : LiteralValue::scalar($arguments[0]->value);

            if (! is_string($literal)) {
                // `config($name)` is a read of a key this file does not name. Silently skipping
                // it would let "no reads" mean "no reads I could see".
                $found[] = new ConfigEntry('?', null, $path, $node->getStartLine(), Confidence::Textual, $kind, 'the key is computed, so it may or may not be this one');

                continue;
            }

            if ($literal !== $key && ! str_starts_with($literal, $key.'.') && ! str_starts_with($key, $literal.'.')) {
                continue;
            }

            $found[] = new ConfigEntry($literal, null, $path, $node->getStartLine(), Confidence::Resolved, $kind);
        }

        return $found;
    }

    /**
     * @return ?array{string, array<int, Node\Arg>}
     */
    private function configCall(Node $node): ?array
    {
        if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name) {
            return Analyser::isGlobalFunction($node->name, 'config')
                ? ['config()', $node->getArgs()]
                : null;
        }

        if (! $node instanceof Node\Expr\StaticCall || ! $node->class instanceof Node\Name || ! $node->name instanceof Node\Identifier) {
            return null;
        }

        $class = Analyser::resolvedName($node->class);

        if (! str_ends_with($class, '\\Config') && $class !== 'Config') {
            return null;
        }

        $method = strtolower($node->name->toString());

        return in_array($method, ['get', 'set', 'has', 'string', 'integer', 'boolean', 'array'], true)
            ? ['Config::'.$node->name->toString(), $node->getArgs()]
            : null;
    }
}

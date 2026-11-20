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

    /**
     * @param  (\Closure(string, string): ?string)|null  $constant  the value of a class constant, for
     *                                                              a key written `Order::MORPH_KEY`
     */
    public function __construct(private readonly Analyser $analyser = new Analyser, private readonly ?\Closure $constant = null) {}

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

            // `Order::MORPH_KEY => Order::class`: a key written as a class constant, read through
            // the class. A morph map keyed this way lost every such entry, without a word.
            if ($key === null && $item->key instanceof Node\Expr\ClassConstFetch && $item->key->class instanceof Node\Name
                && $item->key->name instanceof Node\Identifier && $this->constant instanceof \Closure) {
                $key = ($this->constant)(Analyser::resolvedName($item->key->class), $item->key->name->toString());
            }

            if (! is_string($key) && ! is_int($key)) {
                $this->notes[] = sprintf('%s:%d  a key under "%s" is not a literal, so it is not listed', $path, $item->getStartLine(), $prefix);

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

        $arguments = Analyser::arguments($expression);
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
        $finder = new NodeFinder;
        $found = [];

        // `$config = $app['config']['search'];` then `$config['hosts']`: the variable is the
        // group read, and indexing it reads a key beneath.
        $held = [];

        foreach ($finder->findInstanceOf($statements, Node\Expr\Assign::class) as $assign) {
            $read = $assign->var instanceof Node\Expr\Variable && is_string($assign->var->name) ? $this->accessedKey($assign->expr, []) : null;

            if ($read !== null && $read[0] !== null) {
                $held[$assign->var->name] = $read[0];
            }
        }

        // Only the outermost of `$a['x']['y']`: the inner fetch is part of the same read.
        $inner = [];

        foreach ($finder->findInstanceOf($statements, Node\Expr\ArrayDimFetch::class) as $fetch) {
            $inner[spl_object_id($fetch->var)] = true;
        }

        $candidates = $finder->find($statements, static fn (Node $node): bool => $node instanceof Node\Expr\FuncCall || $node instanceof Node\Expr\StaticCall
            || $node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\ArrayDimFetch);

        foreach ($candidates as $node) {
            if ($node instanceof Node\Expr\ArrayDimFetch && isset($inner[spl_object_id($node)])) {
                continue;
            }

            foreach ($this->accesses($node, $held) as [$literal, $kind, $confidence, $note]) {
                if ($literal === null) {
                    // `config($name)` is a read of a key this file does not name. Silently
                    // skipping it would let "no reads" mean "no reads I could see".
                    $found[] = new ConfigEntry('?', null, $path, $node->getStartLine(), Confidence::Textual, $kind, 'the key is computed, so it may or may not be this one');

                    continue;
                }

                if ($literal !== $key && ! str_starts_with($literal, $key.'.') && ! str_starts_with($key, $literal.'.')) {
                    continue;
                }

                $found[] = new ConfigEntry($literal, null, $path, $node->getStartLine(), $confidence, $kind, $note);
            }
        }

        return $found;
    }

    /** Repository methods that read a key, and the one that writes it. */
    private const array REPOSITORY_READS = ['get', 'has', 'string', 'integer', 'float', 'boolean', 'array', 'collection'];

    /**
     * Each key a node reads or writes: [key or null when computed, how, confidence, note].
     * A write's kind starts `write:`.
     *
     * @param  array<string, string>  $held  variables holding a config group, by name
     * @return list<array{?string, string, Confidence, ?string}>
     */
    private function accesses(Node $node, array $held): array
    {
        // `config(['payments.driver' => 'fake'])` sets every key it lists.
        if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name && Analyser::isGlobalFunction($node->name, 'config')
            && ($first = Analyser::arguments($node)[0]->value ?? null) instanceof Node\Expr\Array_) {
            $writes = [];

            foreach ($first->items as $item) {
                $written = $item->key instanceof Node\Expr ? LiteralValue::scalar($item->key) : null;
                $writes[] = [is_string($written) ? $written : null, 'write: config([...])', Confidence::Resolved, null];
            }

            return $writes;
        }

        // `config()` with nothing is the repository; the method called on it is the read.
        if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name && Analyser::isGlobalFunction($node->name, 'config') && Analyser::arguments($node) === []) {
            return [];
        }

        $call = $this->configCall($node);

        if ($call !== null) {
            [$kind, $arguments] = $call;
            $literal = $arguments === [] ? null : LiteralValue::scalar($arguments[0]->value);

            return [[is_string($literal) ? $literal : null, str_ends_with(strtolower($kind), '::set') ? 'write: '.$kind : $kind, Confidence::Resolved, null]];
        }

        // `config()->get('x')`, `app('config')->get('x')`, `$this->config->get('x')`.
        if ($node instanceof Node\Expr\MethodCall && $node->name instanceof Node\Identifier) {
            $method = strtolower($node->name->toString());
            $repository = $this->repository($node->var);

            if ($repository === null || (! in_array($method, self::REPOSITORY_READS, true) && $method !== 'set')) {
                return [];
            }

            $literal = ($first = Analyser::arguments($node)[0]->value ?? null) instanceof Node\Expr ? LiteralValue::scalar($first) : null;
            $kind = $repository[0].'->'.$node->name->toString();

            return [[is_string($literal) ? $literal : null, $method === 'set' ? 'write: '.$kind : $kind, $repository[1], $repository[2]]];
        }

        $read = $this->accessedKey($node, $held);

        return $read === null ? [] : [[$read[0], $read[1], $read[2], $read[3]]];
    }

    /**
     * The key an array fetch reads: `$app['config']['search']` is `search`, `$config['hosts']`
     * after `$config = …['search']` is `search.hosts`, `config('a')['b']` is `a.b`.
     *
     * @param  array<string, string>  $held
     * @return ?array{?string, string, Confidence, ?string}
     */
    private function accessedKey(Node $node, array $held): ?array
    {
        $segments = [];
        $base = $node;

        while ($base instanceof Node\Expr\ArrayDimFetch) {
            $dim = $base->dim instanceof Node\Expr ? LiteralValue::scalar($base->dim) : null;

            if (! is_string($dim) && ! is_int($dim)) {
                return null;
            }

            array_unshift($segments, (string) $dim);
            $base = $base->var;
        }

        if ($segments === []) {
            return null;
        }

        if ($base instanceof Node\Expr\Variable && is_string($base->name) && isset($held[$base->name])) {
            return [$held[$base->name].'.'.implode('.', $segments), sprintf('$%s[...]', $base->name), Confidence::Inferred, 'read through a variable holding the config group'];
        }

        // `$app['config']['a']`: the first segment is the container's `config`.
        if ($segments[0] === 'config' && count($segments) > 1 && ! $base instanceof Node\Expr\FuncCall) {
            return [implode('.', array_slice($segments, 1)), "['config'][...]", Confidence::Resolved, null];
        }

        $repository = $this->repository($base);

        return $repository === null ? null : [implode('.', $segments), $repository[0].'[...]', $repository[1], $repository[2]];
    }

    /**
     * Whether an expression is the config repository: `config()`, `app('config')`,
     * `$app->make('config')`, `$app['config']`, or a variable or property named for it, which
     * is how an injected `Repository` is usually held.
     *
     * @return ?array{string, Confidence, ?string}
     */
    private function repository(Node $expression): ?array
    {
        if ($expression instanceof Node\Expr\FuncCall && $expression->name instanceof Node\Name) {
            $first = Analyser::arguments($expression)[0]->value ?? null;

            if (Analyser::isGlobalFunction($expression->name, 'config') && $first === null) {
                return ['config()', Confidence::Resolved, null];
            }

            return Analyser::isGlobalFunction($expression->name, 'app') && $first instanceof Node\Expr && LiteralValue::scalar($first) === 'config'
                ? ["app('config')", Confidence::Resolved, null]
                : null;
        }

        if ($expression instanceof Node\Expr\ArrayDimFetch && $expression->dim instanceof Node\Expr && LiteralValue::scalar($expression->dim) === 'config') {
            return ["['config']", Confidence::Resolved, null];
        }

        if ($expression instanceof Node\Expr\MethodCall && $expression->name instanceof Node\Identifier && strtolower($expression->name->toString()) === 'make'
            && ($first = Analyser::arguments($expression)[0]->value ?? null) instanceof Node\Expr && LiteralValue::scalar($first) === 'config') {
            return ["make('config')", Confidence::Resolved, null];
        }

        $name = match (true) {
            $expression instanceof Node\Expr\Variable && is_string($expression->name) => $expression->name,
            $expression instanceof Node\Expr\PropertyFetch && $expression->name instanceof Node\Identifier => $expression->name->toString(),
            default => null,
        };

        return $name !== null && preg_match('/config$/i', $name) === 1
            ? [($expression instanceof Node\Expr\PropertyFetch ? '->' : '$').$name, Confidence::Inferred, 'a config repository, by its name']
            : null;
    }

    /**
     * @return ?array{string, array<int, Node\Arg>}
     */
    private function configCall(Node $node): ?array
    {
        if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name) {
            return Analyser::isGlobalFunction($node->name, 'config')
                ? ['config()', Analyser::arguments($node)]
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
            ? ['Config::'.$node->name->toString(), Analyser::arguments($node)]
            : null;
    }
}

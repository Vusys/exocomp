<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Analysis;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use Vusys\Exocomp\Source\ParseFailure;
use Vusys\Exocomp\Source\SourceFile;

/**
 * Parses one file and extracts its declarations.
 *
 * The parser is created for the newest supported PHP version rather than a pinned one. That is
 * a correctness commitment, not a default: the reason for owning this engine at all is that
 * the obvious alternative could not see PHP 8.4 syntax, and a code-intelligence tool that
 * mis-parses a property hook is exactly the confidently-wrong failure the design rules out.
 *
 * Names are resolved with `replaceNodes: false` so nodes keep the spelling the file used —
 * `show` must be able to print the original bytes — while `extends`/`implements`/`use` still
 * resolve to fully-qualified names via the `resolvedName` attribute.
 */
final readonly class Analyser
{
    private Parser $parser;

    private NameResolver $nameResolver;

    public function __construct()
    {
        $this->parser = (new ParserFactory)->createForNewestSupportedVersion();
        $this->nameResolver = new NameResolver(null, ['replaceNodes' => false]);
    }

    public function analyse(SourceFile $file): FileAnalysis
    {
        return $this->analyseWithAst($file)[0];
    }

    /**
     * The same analysis, keeping the resolved tree.
     *
     * Callers that go on to walk the AST themselves — reference finding, structural query —
     * would otherwise parse the file a second time, which on a corpus-wide sweep doubles the
     * only expensive part of the work.
     *
     * @return array{FileAnalysis, array<int, Node>}
     */
    public function analyseWithAst(SourceFile $file): array
    {
        try {
            $statements = $this->parser->parse($file->contents);
        } catch (Error $error) {
            throw new ParseFailure($file->displayPath, $error->getStartLine(), $error->getRawMessage());
        }

        if ($statements === null) {
            throw new ParseFailure($file->displayPath, 1, 'the parser produced no statements');
        }

        // Every visitor rides the one traversal. Names have to be resolved before anything can
        // be read off the tree, and both the anonymous-class and referenced-name collectors want
        // the same walk — a separate pass for each measured 14% slower over a large corpus.
        $anonymous = new AnonymousClassCollector($file);
        $references = new ReferenceCollector;
        $statements = new NodeTraverser($this->nameResolver, $anonymous, $references)->traverse($statements);

        $collector = new DeclarationCollector($file);
        $collector->collect($statements);

        return [
            new FileAnalysis(
                $file,
                $collector->declarations(),
                $collector->imports(),
                $collector->namespaces(),
                $anonymous->found($collector),
                $references->names(),
            ),
            $statements,
        ];
    }

    /**
     * Whether a call names a particular global function.
     *
     * An unqualified call inside a namespace resolves to the current namespace first and only
     * then falls back to global, so the name resolver reports `config()` written in
     * `App\Providers` as `App\Providers\config`. Comparing that against `config` finds
     * nothing — which is how a search for a helper call comes back empty in exactly the files
     * that are organised into namespaces, meaning all of them.
     */
    public static function isGlobalFunction(Node\Name $name, string $function): bool
    {
        if ($name->isUnqualified()) {
            return strcasecmp($name->toString(), $function) === 0;
        }

        return strcasecmp(ltrim(self::resolvedName($name), '\\'), $function) === 0;
    }

    /**
     * Resolves the fully-qualified name a `Name` node refers to, preferring what the name
     * resolver worked out over the literal spelling. A relative or imported short name means
     * nothing on its own — resolving it is precisely the thing grep cannot do.
     */
    /**
     * A name written inside a file, resolved the way PHP itself would resolve it: leading
     * backslash means absolute, `self`/`static` mean the enclosing class, an alias wins over
     * everything, and a bare name falls back to the current namespace.
     *
     * This is the half of name resolution a textual search cannot do, and it is needed wherever
     * a name is read out of something the parser did not resolve for us — a docblock, most of
     * all, since `@method Notifier fetch()` means whatever `Notifier` means in that file.
     *
     * @param  list<Import>  $imports
     */
    public static function qualify(string $name, ?string $classFqn, array $imports): string
    {
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }

        if (in_array(strtolower($name), ['self', 'static', '$this'], true)) {
            return $classFqn ?? $name;
        }

        $head = explode('\\', $name)[0];

        foreach ($imports as $import) {
            if (strcasecmp($import->alias, $head) === 0) {
                $rest = array_slice(explode('\\', $name), 1);

                return $rest === [] ? $import->fqn : $import->fqn.'\\'.implode('\\', $rest);
            }
        }

        if ($classFqn !== null && ! str_contains($name, '\\')) {
            $segments = explode('\\', $classFqn);
            array_pop($segments);
            $namespace = implode('\\', $segments);

            if ($namespace !== '') {
                return $namespace.'\\'.$name;
            }
        }

        return $name;
    }

    /**
     * A call's arguments, or none for a first-class callable (`$x->m(...)`), which has no
     * arguments yet. php-parser asserts against asking it for them, so under `zend.assertions=1`
     * (the development default) calling `getArgs()` on one is a fatal error.
     *
     * @return list<Node\Arg>
     */
    public static function arguments(Node\Expr\CallLike $call): array
    {
        return $call->isFirstClassCallable() ? [] : array_values($call->getArgs());
    }

    public static function resolvedName(Node\Name $name): string
    {
        $resolved = $name->getAttribute('resolvedName');

        if ($resolved instanceof Node\Name) {
            return $resolved->toString();
        }

        $namespaced = $name->getAttribute('namespacedName');

        if ($namespaced instanceof Node\Name) {
            return $namespaced->toString();
        }

        return $name->toString();
    }
}

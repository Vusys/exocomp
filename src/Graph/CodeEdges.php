<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Graph;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use Vusys\Exocomp\Analysis\Analyser;
use Vusys\Exocomp\Analysis\Declaration;
use Vusys\Exocomp\Framework\Laravel\AuthModel;
use Vusys\Exocomp\Index\SymbolGraph;
use Vusys\Exocomp\Index\SymbolRecord;
use Vusys\Exocomp\Inference\CallScanner;
use Vusys\Exocomp\Inference\Magic;
use Vusys\Exocomp\Inference\OutgoingCall;
use Vusys\Exocomp\Inference\TypeResolver;
use Vusys\Exocomp\Project\ClassSource;
use Vusys\Exocomp\Project\InstalledFunctions;
use Vusys\Exocomp\Project\LoadedClass;
use Vusys\Exocomp\Project\Project;
use Vusys\Exocomp\Project\SymbolLocator;
use Vusys\Exocomp\Project\SymbolNotFound;
use Vusys\Exocomp\Source\ParseFailure;
use Vusys\Exocomp\Source\SourceFile;
use Vusys\Exocomp\Symbol\Identifier;
use Vusys\Exocomp\Symbol\SymbolKind;
use Vusys\Exocomp\Symbol\SymbolRef;

/**
 * What one declaration calls — the language-level edges, and the only ones the parser can see
 * on its own.
 *
 * This is the single implementation behind both `calls-from` and the graph walk. Two of them
 * would drift, and a graph that disagreed with the command a reader used to check it would be
 * worse than no graph: the whole value of the answer is that it can be verified a step at a
 * time.
 *
 * Bodies are found through the source rather than the index, because a walk leaves the indexed
 * corpus almost at once — a controller calls a framework base class within two steps — and the
 * index has no row for anything in `vendor/`. Composer's autoload map places those files
 * exactly, so one can be read without paying to index sixty thousand.
 */
final class CodeEdges
{
    /** @var array<string, Body|null> */
    private array $bodies = [];

    /** @var array<string, list<OutgoingCall>> */
    private array $calls = [];

    /** @var array<string, Miss> */
    private array $misses = [];

    private ?TypeResolver $types = null;

    public function __construct(
        private readonly Project $project,
        private readonly SymbolGraph $graph,
        private readonly ClassSource $source,
        private readonly ?SymbolLocator $locator = null,
        private readonly Analyser $analyser = new Analyser,
    ) {}

    /**
     * The declaration a node names, or null when nothing readable declares it.
     */
    public function body(string $node): ?Body
    {
        $key = Identifier::fold($node);

        // Cached including the misses, which are most of it — see the same note on
        // {@see ClassSource::load()}.
        if (! array_key_exists($key, $this->bodies)) {
            $this->bodies[$key] = $this->read($node);
        }

        return $this->bodies[$key];
    }

    /**
     * Why a node has no body: a hole in the answer, or the edge of what source there is.
     */
    public function miss(string $node): Miss
    {
        $this->body($node);

        return $this->misses[Identifier::fold($node)] ?? Miss::gap('no declaration for it could be read');
    }

    /**
     * @return list<OutgoingCall>
     */
    public function callsFrom(Body $body): array
    {
        $key = Identifier::fold($body->fqn);

        if (isset($this->calls[$key])) {
            return $this->calls[$key];
        }

        // Built once and kept. A walk expands hundreds of bodies, and both of these carry the
        // caches that make the whole thing affordable — a fresh resolver per body threw away
        // every memoised member lookup and re-confirmed the framework's conventions each time,
        // which cost a hundred-endpoint sweep about as long again as it had taken in total.
        $types = $this->types ??= new TypeResolver($this->graph, Magic::forProject($this->graph, $this->source, $this->project), null, InstalledFunctions::forProject($this->project), $this->source, AuthModel::lazily($this->project));
        $scope = $types->scopeFor($body->classFqn, $body->parentFqn, $body->node, $body->analysis->imports);
        $scanner = new CallScanner($body->analysis, $types, $scope, $this->graph);

        new NodeTraverser($scanner)->traverse([$body->node]);

        return $this->calls[$key] = $scanner->calls();
    }

    /**
     * Whether a file belongs to a dependency rather than to the code being asked about.
     *
     * The distinction earns its place in the traversal: expanding into the framework is
     * unbounded in practice, and a reader chasing a path through their own application does
     * not want four hundred nodes of collection plumbing in the answer.
     */
    public static function isDependency(string $displayPath): bool
    {
        return str_starts_with($displayPath, 'vendor/');
    }

    private function read(string $node): ?Body
    {
        $parts = explode('::', $node);
        $key = Identifier::fold($node);

        if (isset($parts[1]) && $parts[0] !== '') {
            $body = $this->method($parts[0], $parts[1]);

            if ($body instanceof Body) {
                return $body;
            }

            $this->misses[$key] = $this->whyNoMethod($parts[0], $parts[1]);

            return null;
        }

        $located = $this->located($node);

        if ($located instanceof Body) {
            return $located;
        }

        // A named function with no source is the ordinary case, not a hole: `str_ends_with` and
        // every framework helper resolve to a name that is unambiguous and has nothing readable
        // behind it. Only a function the corpus itself declares and that still could not be
        // read is something a reader has lost.
        $this->misses[$key] = $this->declaresFunction($node)
            ? Miss::gap(sprintf('%s is declared in the corpus but its body could not be read', $node))
            : Miss::edgeOfTheWorld('a built-in or dependency function, with no source here to walk into');

        return null;
    }

    /**
     * A missing method is three different situations and they are worth different amounts.
     */
    private function whyNoMethod(string $class, string $method): Miss
    {
        $loaded = $this->source->load($class);

        if (! $loaded instanceof LoadedClass) {
            return Miss::gap(sprintf('no class named %s could be read here or through the autoload map', $class));
        }

        // A class with no constructor is entered by `new` without running anything. There is no
        // step being lost.
        if (strcasecmp($method, '__construct') === 0) {
            return Miss::edgeOfTheWorld(sprintf('%s declares no constructor', $class));
        }

        $magic = $this->declaresMagic($loaded, '__call');

        if ($magic !== null) {
            return Miss::gap(sprintf(
                '%s declares no %s(); %s declares __call(), so this resolves at runtime through that',
                $class,
                $method,
                $magic,
            ));
        }

        // Saying "a parent declares it" of a class that has no parent is the confidently wrong
        // answer this tool exists not to give. The claim is only available when the ancestry
        // actually ran off the end of the readable source.
        $unreachable = $this->graph->members($class)['unreachable'];

        if ($unreachable === []) {
            return Miss::gap(sprintf(
                '%s declares no %s(), and nothing in its readable ancestry does either',
                $class,
                $method,
            ));
        }

        return Miss::gap(sprintf(
            '%s declares no %s(); its ancestry leaves the readable source at %s, and a parent or trait there may',
            $class,
            $method,
            $unreachable[0]->fqn,
        ));
    }

    /**
     * The nearest class in the readable ancestry that declares a magic method, if any.
     *
     * A class with `__call` and one without produce the same "declares no such method" today,
     * and they are not the same statement: one is a call that will not run, the other is a call
     * whose far end this tool cannot see.
     */
    private function declaresMagic(LoadedClass $class, string $magic): ?string
    {
        foreach ($this->source->withAncestors($class->fqn) as $ancestor) {
            foreach ($ancestor->node->getMethods() as $method) {
                if (strcasecmp($method->name->toString(), $magic) === 0) {
                    return $ancestor->fqn;
                }
            }
        }

        return null;
    }

    private function declaresFunction(string $name): bool
    {
        return array_any(
            $this->graph->byFqn(ltrim($name, '\\')),
            static fn (SymbolRecord $record): bool => $record->kind === SymbolKind::FunctionDecl,
        );
    }

    /**
     * A method, found on the class itself or on whichever ancestor or trait declares it.
     *
     * The walk is breadth-first and stops at the first declaration, rather than materialising
     * the ancestry: a model descends from a framework base class with a dozen traits, and
     * reading all of them to find one method is most of a second per node.
     */
    private function method(string $class, string $method): ?Body
    {
        $queue = [$class];
        $seen = [];

        while ($queue !== []) {
            $current = array_shift($queue);
            $key = Identifier::fold($current);

            if (isset($seen[$key]) || count($seen) > 64) {
                continue;
            }

            $seen[$key] = true;
            $loaded = $this->source->load($current);

            if (! $loaded instanceof LoadedClass) {
                continue;
            }

            $declared = $loaded->node->getMethod($method);

            if ($declared instanceof Node\Stmt\ClassMethod) {
                return new Body(
                    fqn: $loaded->fqn.'::'.$declared->name->toString(),
                    path: $loaded->path,
                    node: $declared,
                    analysis: $loaded->analysis,
                    classFqn: $loaded->fqn,
                    parentFqn: $this->parentOf($loaded),
                );
            }

            foreach ($this->source->parentsOf($loaded) as $parent) {
                $queue[] = $parent;
            }
        }

        return null;
    }

    private function parentOf(LoadedClass $class): ?string
    {
        $extends = $class->node instanceof Node\Stmt\Class_ ? $class->node->extends : null;

        return $extends instanceof Node\Name ? $extends->toString() : null;
    }

    /**
     * The fallback: a free function, or a member the index can place and the source cannot.
     */
    private function located(string $node): ?Body
    {
        if (! $this->locator instanceof SymbolLocator) {
            return null;
        }

        try {
            $ref = SymbolRef::parse($node);
            // Speculative: a walk reads as far as it can, and must never be corrected onto a
            // different name. Asked about `response()`, a corrected lookup answered with a class
            // called Response and walked into it.
            $located = $this->locator->locateOwner($ref, speculative: true);
        } catch (SymbolNotFound|\InvalidArgumentException) {
            return null;
        }

        $owner = $located->declaration;
        $declaration = $ref->hasMember() ? $owner->member((string) $ref->member, false) : $owner;

        if (! $declaration instanceof Declaration || (! $ref->hasMember() && $declaration->kind !== SymbolKind::FunctionDecl)) {
            return null;
        }

        $path = $located->analysis->file->displayPath;

        try {
            [$analysis, $statements] = $this->analyser->analyseWithAst(
                SourceFile::fromPath($this->project->path($path), $path),
            );
        } catch (ParseFailure) {
            return null;
        }

        $function = $this->functionAt($statements, $declaration->startLine, $declaration->endLine);

        if (! $function instanceof Node\FunctionLike) {
            return null;
        }

        return new Body(
            fqn: $declaration->fqn,
            path: $path,
            node: $function,
            analysis: $analysis,
            classFqn: $ref->hasMember() ? $owner->fqn : null,
            parentFqn: $owner->extends[0] ?? null,
        );
    }

    /**
     * @param  array<int, Node>  $nodes
     */
    private function functionAt(array $nodes, int $startLine, int $endLine): ?Node\FunctionLike
    {
        foreach ($nodes as $node) {
            if ($node instanceof Node\FunctionLike && $node->getStartLine() === $startLine && $node->getEndLine() === $endLine) {
                return $node;
            }

            foreach ($node->getSubNodeNames() as $name) {
                $value = $node->{$name};
                $children = array_values(array_filter(is_array($value) ? $value : [$value], static fn (mixed $child): bool => $child instanceof Node));
                $found = $this->functionAt($children, $startLine, $endLine);

                if ($found instanceof Node\FunctionLike) {
                    return $found;
                }
            }
        }

        return null;
    }
}

<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Inference;

use PhpParser\Node;
use PhpParser\Node\Expr;
use Vusys\Exocomp\Index\SymbolRecord;
use Vusys\Exocomp\Project\ClassSource;
use Vusys\Exocomp\Project\LoadedClass;
use Vusys\Exocomp\Symbol\Confidence;
use Vusys\Exocomp\Symbol\Identifier;
use Vusys\Exocomp\Symbol\SymbolKind;

/**
 * The one shape of `__call` and `__get` that can be read: a single statement handing the name
 * straight on to something typed.
 *
 * This is the rung that sounds like the whole problem and turns out to be almost none of it. A
 * measured Laravel application had one `__call` body in four and a half thousand files. It is
 * built because it is the same rule already applied to container bindings — a closure returning
 * a single `new X` is that X, and anything more involved is declined — and because consistency
 * about where evaluation stops is worth more than the cases it catches.
 *
 * A body that computes its target, branches on a prefix, or reads out of an array is declined.
 * That is not a gap to be closed later: following it would mean evaluating the method, and a
 * static reader that starts evaluating is a static reader that starts inventing.
 */
final class Forwarders implements MagicSource
{
    /** @var array<string, list<array{string, string}>> */
    private array $forwarders = [];

    public function __construct(
        private readonly ClassSource $source,
        private readonly Members $members,
    ) {}

    public function method(string $ownerFqn, string $name): ?MagicMember
    {
        return $this->through($ownerFqn, $name, '__call', SymbolKind::Method);
    }

    public function property(string $ownerFqn, string $name): ?MagicMember
    {
        return $this->through($ownerFqn, $name, '__get', SymbolKind::Property);
    }

    /**
     * A forwarder passes the name straight through, so the call site writes the declared name.
     *
     * @return list<string>
     */
    public function spellings(string $ownerFqn, string $declaredName): array
    {
        return [];
    }

    private function through(string $ownerFqn, string $name, string $magic, SymbolKind $kind): ?MagicMember
    {
        foreach ($this->forwardersOf($ownerFqn, $magic) as [$class, $target]) {
            // Knowing where the name goes is not knowing that anything is there to receive it.
            // Naming `Target::whatever` on the strength of the forwarder alone would repeat, one
            // rung down, exactly the mistake this ladder was built to stop.
            if ($this->members->isComplete($target) && ! $this->members->declared($target, $name, $kind) instanceof SymbolRecord) {
                return null;
            }

            return MagicMember::spelled(
                $target.'::'.$name,
                null,
                Confidence::Inferred,
                sprintf('%s() on %s forwards to %s', $magic, $class, $target),
            );
        }

        return null;
    }

    /**
     * Which classes in the ancestry forward this magic method, and to what — nearest first.
     *
     * Kept per owner because it is asked once per member looked up, and a reference search
     * looks up members on the same receiver type hundreds of times. Walking the ancestry each
     * time re-parsed whichever classes in it had been evicted since.
     *
     * @return list<array{string, string}>
     */
    private function forwardersOf(string $ownerFqn, string $magic): array
    {
        $key = Identifier::fold($ownerFqn).' '.$magic;

        if (! isset($this->forwarders[$key])) {
            $this->forwarders[$key] = [];

            foreach ($this->source->withAncestors($ownerFqn) as $class) {
                $target = $this->forwardsTo($class, $magic);

                if ($target !== null) {
                    $this->forwarders[$key][] = [$class->fqn, $target];
                }
            }
        }

        return $this->forwarders[$key];
    }

    /**
     * The class a magic method hands everything to, when it hands everything to one thing.
     */
    private function forwardsTo(LoadedClass $class, string $magic): ?string
    {
        $body = $this->soleStatement($class, $magic);

        if (! $body instanceof Node\Stmt\Return_ || ! $body->expr instanceof Expr) {
            return null;
        }

        $receiver = match (true) {
            $body->expr instanceof Expr\MethodCall,
            $body->expr instanceof Expr\NullsafeMethodCall,
            $body->expr instanceof Expr\PropertyFetch,
            $body->expr instanceof Expr\NullsafePropertyFetch => $body->expr,
            default => null,
        };

        // The member being fetched has to be the one that was asked for. A body that forwards a
        // *fixed* name is not a forwarder, it is a method that happens to be spelled oddly.
        if ($receiver === null || $receiver->name instanceof Node\Identifier) {
            return null;
        }

        return $this->declaredTypeOf($class, $receiver->var);
    }

    private function soleStatement(LoadedClass $class, string $magic): ?Node\Stmt
    {
        foreach ($class->node->getMethods() as $method) {
            if (strcasecmp($method->name->toString(), $magic) !== 0) {
                continue;
            }

            $statements = $method->stmts ?? [];

            return count($statements) === 1 ? $statements[0] : null;
        }

        return null;
    }

    /**
     * The declared type of `$this->something`, read off the class's own declarations.
     */
    private function declaredTypeOf(LoadedClass $class, Node $expression): ?string
    {
        if (! $expression instanceof Expr\PropertyFetch || ! $expression->name instanceof Node\Identifier) {
            return null;
        }

        $var = $expression->var;

        if (! $var instanceof Expr\Variable || $var->name !== 'this') {
            return null;
        }

        // Through the index rather than the node, so that a constructor-promoted property —
        // which is how most of these are actually written — is found without special-casing.
        $property = $this->members->declared($class->fqn, $expression->name->toString(), SymbolKind::Property);

        return $property instanceof SymbolRecord ? $property->typeFqn : null;
    }
}

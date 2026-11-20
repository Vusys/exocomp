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
use Vusys\Exocomp\Symbol\Identifier;

/**
 * The policy registered for one model: in a provider's `$policies` array, with
 * `Gate::policy(Model::class, Policy::class)`, or by `#[UsePolicy(…)]` on the model. Where none
 * is registered, Laravel guesses one by name, which the caller handles: that is a convention, not
 * something the source says.
 */
final class PolicyReader
{
    private const string USE_POLICY = 'Illuminate\Database\Eloquent\Attributes\UsePolicy';

    private const string GATE = 'Illuminate\Support\Facades\Gate';

    /** @var list<DispatchEdge> */
    private array $policies = [];

    public function __construct(
        private readonly Analyser $analyser = new Analyser,
    ) {}

    /**
     * @param  list<string>  $paths  display paths of the files that may register one
     * @param  callable(string): string  $absolute
     */
    public function read(string $model, array $paths, callable $absolute): ?DispatchEdge
    {
        foreach ($paths as $path) {
            $file = $absolute($path);

            if (! is_file($file)) {
                continue;
            }

            try {
                [, $statements] = $this->analyser->analyseWithAst(SourceFile::fromPath($file, $path));
            } catch (ParseFailure) {
                continue;
            }

            $this->policiesProperty($statements, $model, $path);
            $this->policyCalls($statements, $model, $path);
            $this->attribute($statements, $model, $path);
        }

        return $this->policies[0] ?? null;
    }

    /**
     * @param  array<Node>  $statements
     */
    private function policiesProperty(array $statements, string $model, string $path): void
    {
        foreach (new NodeFinder()->findInstanceOf($statements, Node\Stmt\Property::class) as $property) {
            foreach ($property->props as $item) {
                if ($item->name->toString() !== 'policies' || ! $item->default instanceof Node\Expr\Array_) {
                    continue;
                }

                foreach ($item->default->items as $entry) {
                    if ($entry->key instanceof Node\Expr && $this->is($entry->key, $model)) {
                        $this->add($model, $entry->value, $path, $entry->getStartLine(), 'registered in $policies');
                    }
                }
            }
        }
    }

    /**
     * @param  array<Node>  $statements
     */
    private function policyCalls(array $statements, string $model, string $path): void
    {
        foreach (new NodeFinder()->findInstanceOf($statements, Node\Expr\StaticCall::class) as $call) {
            if (! $call->class instanceof Node\Name || ! $call->name instanceof Node\Identifier
                || strtolower($call->name->toString()) !== 'policy'
                || Identifier::fold(Analyser::resolvedName($call->class)) !== Identifier::fold(self::GATE)) {
                continue;
            }

            [$subject, $policy] = [$call->args[0] ?? null, $call->args[1] ?? null];

            if ($subject instanceof Node\Arg && $policy instanceof Node\Arg && $this->is($subject->value, $model)) {
                $this->add($model, $policy->value, $path, $call->getStartLine(), 'registered by Gate::policy()');
            }
        }
    }

    /**
     * @param  array<Node>  $statements
     */
    private function attribute(array $statements, string $model, string $path): void
    {
        foreach (new NodeFinder()->findInstanceOf($statements, Node\Stmt\Class_::class) as $class) {
            if (Identifier::fold($class->namespacedName?->toString() ?? '') !== Identifier::fold($model)) {
                continue;
            }

            foreach ($class->attrGroups as $group) {
                foreach ($group->attrs as $attribute) {
                    $argument = $attribute->args[0] ?? null;

                    if ($argument instanceof Node\Arg && Identifier::fold(Analyser::resolvedName($attribute->name)) === Identifier::fold(self::USE_POLICY)) {
                        $this->add($model, $argument->value, $path, $attribute->getStartLine(), 'registered by #[UsePolicy] on the model');
                    }
                }
            }
        }
    }

    private function is(Node\Expr $expression, string $model): bool
    {
        return Identifier::fold(LiteralValue::className($expression) ?? '') === Identifier::fold($model);
    }

    private function add(string $model, Node\Expr $value, string $path, int $line, string $how): void
    {
        $policy = LiteralValue::className($value);

        if ($policy !== null) {
            $this->policies[] = new DispatchEdge('policy', $model, $policy, $path, $line, Confidence::Resolved, $how);
        }
    }
}

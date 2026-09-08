<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Console\Command;

use PhpParser\Node\Stmt\ClassMethod;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Vusys\Exocomp\Console\CommandContext;
use Vusys\Exocomp\Console\IndexedFrameworkCommand;
use Vusys\Exocomp\Framework\Laravel\FacadeResolver;
use Vusys\Exocomp\Output\Address;
use Vusys\Exocomp\Output\Result;
use Vusys\Exocomp\Output\Row;
use Vusys\Exocomp\Output\Section;
use Vusys\Exocomp\Project\ClassSource;
use Vusys\Exocomp\Project\LoadedClass;
use Vusys\Exocomp\Project\SymbolNotFound;
use Vusys\Exocomp\Symbol\Confidence;
use Vusys\Exocomp\Symbol\SymbolRef;

/**
 * A facade call to the class and method that actually run.
 *
 * 678 measured searches were facade questions, and 205 more were reading `vendor/` by hand to
 * answer exactly this. The chain has three hops and none is visible from the call site:
 * `Storage::disk()` goes to a protected accessor method, which returns a container key, which
 * the framework's own bootstrap binds to a class. Following it manually means opening three
 * files in a directory nobody wants to be in.
 */
#[AsCommand(name: 'facade', description: 'Resolve a facade — and optionally one of its methods — to the class that runs')]
final class FacadeCommand extends IndexedFrameworkCommand
{
    protected function configure(): void
    {
        parent::configure();

        $this->addArgument('facade', InputArgument::REQUIRED, 'A facade, optionally with a method: Storage or Storage::disk');
    }

    protected function answer(InputInterface $input, CommandContext $context): Result
    {
        $notes = [];
        $graph = $this->graph($input, $context, $notes);
        $container = $this->container($input, $context, $graph);

        $ref = SymbolRef::parse($this->stringArgument($input, 'facade') ?? '');
        $source = new ClassSource($context->project, $graph, $context->locator);
        $resolver = new FacadeResolver($source, $container);

        $indexed = $graph->byName($ref->owner);
        $facade = $resolver->facadeClass($ref->owner, $indexed === [] ? null : $indexed[0]->fqn);

        if (! $facade instanceof LoadedClass) {
            throw new SymbolNotFound($ref->owner, sprintf(
                '"%s" is not a facade — nothing by that name extends %s. Facades are tried as %s%s first, then as a name in this project.',
                $ref->owner,
                'Illuminate\Support\Facades\Facade',
                'Illuminate\Support\Facades\\',
                $ref->owner,
            ));
        }

        $accessor = $resolver->accessor($facade);

        if ($accessor === null) {
            throw new SymbolNotFound($facade->fqn, sprintf(
                '%s declares no getFacadeAccessor(), so there is nothing to resolve it through',
                $facade->fqn,
            ));
        }

        [$key, $isClassName] = $accessor;
        [$concrete, $confidence, $because] = $resolver->concrete($key, $isClassName);

        $rows = [new Row(
            file: $facade->path,
            line: $facade->node->getStartLine(),
            column: null,
            fqn: $concrete,
            kind: 'facade',
            text: sprintf('%s  %s  →  %s', Address::of($facade->path, $facade->node->getStartLine()), $facade->fqn, $concrete ?? '?'),
            confidence: $confidence,
            note: $because,
            extra: ['facade' => $facade->fqn, 'accessor' => $key, 'concrete' => $concrete],
            carriesAddress: true,
        )];

        $sections = [new Section('', $rows)];
        $unresolved = [];

        if ($ref->hasMember()) {
            [$found, $missing] = $this->method($source, $concrete, (string) $ref->member, $confidence);
            $sections[] = new Section('method', $found);
            $unresolved = $missing;
        }

        return new Result(
            header: $isClassName
                ? sprintf('%s names its class directly', $facade->fqn)
                : sprintf('%s resolves through the container key "%s"', $facade->fqn, $key),
            sections: $sections,
            unresolvedTitle: 'not resolved',
            unresolved: $unresolved,
            notes: [...$notes, $because, ...$resolver->notes(), ...$container->notes(), ...$context->notes()],
        );
    }

    /**
     * The method as it is declared on the concrete class.
     *
     * This reads the class rather than the index, because the concrete class is usually a
     * framework one and `vendor/` is not indexed. Asking the index would report
     * `FilesystemManager::disk()` as undeclared — which is false, and false in the worst
     * direction, since the message would read as a fact about the framework rather than as a
     * gap in what had been indexed.
     *
     * A facade method that genuinely is not declared is the ordinary case rather than an error:
     * the framework's managers forward what they do not implement, and plenty of the rest are
     * macros. Both are reported as what they are.
     *
     * @return array{list<Row>, list<Row>}
     */
    private function method(ClassSource $source, ?string $concrete, string $member, Confidence $confidence): array
    {
        if ($concrete === null) {
            return [[], [new Row(
                file: '',
                line: null,
                column: null,
                fqn: null,
                kind: 'method',
                text: $member,
                confidence: Confidence::Textual,
                note: 'the concrete class is unknown, so the method cannot be placed',
            )]];
        }

        $found = $this->declaredMethod($source, $concrete, $member, $confidence, 0);

        if ($found instanceof Row) {
            return [[$found], []];
        }

        $readable = $source->load($concrete) instanceof LoadedClass;

        return [[], [new Row(
            file: $concrete,
            line: null,
            column: null,
            fqn: $concrete,
            kind: 'method',
            text: $concrete.'::'.$member,
            confidence: Confidence::Textual,
            note: $readable
                ? 'not declared anywhere on the concrete class — it is forwarded, macroed, or handled by __call, none of which is resolvable from the source'
                : 'the concrete class could not be read, so whether it declares this method is unknown',
        )]];
    }

    /**
     * The method, looked for on the class and then up its parents and traits.
     */
    private function declaredMethod(ClassSource $source, string $fqn, string $member, Confidence $confidence, int $depth): ?Row
    {
        if ($depth > 8) {
            return null;
        }

        $class = $source->load($fqn);

        if (! $class instanceof LoadedClass) {
            return null;
        }

        $method = $class->node->getMethod($member);

        if ($method instanceof ClassMethod) {
            return new Row(
                file: $class->path,
                line: $method->getStartLine(),
                column: null,
                fqn: $fqn.'::'.$method->name->toString(),
                kind: 'method',
                text: sprintf('%s  %s::%s()', Address::of($class->path, $method->getStartLine()), $fqn, $method->name->toString()),
                // The method is exactly as well known as the class it was looked for on: a
                // facade that names its class outright gives a resolved answer, and one reached
                // through a container alias gives an inferred one.
                confidence: $confidence,
                note: $depth === 0 ? null : 'inherited into '.$fqn,
                extra: ['method' => $method->name->toString()],
                carriesAddress: true,
            );
        }

        foreach ($this->parentsOf($class) as $parent) {
            $found = $this->declaredMethod($source, $parent, $member, $confidence, $depth + 1);

            if ($found instanceof Row) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function parentsOf(LoadedClass $class): array
    {
        foreach ($class->analysis->declarations as $declaration) {
            if (strcasecmp($declaration->fqn, $class->fqn) === 0) {
                return [...$declaration->traits, ...$declaration->extends];
            }
        }

        return [];
    }
}

<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Console\Command;

use InvalidArgumentException;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\NodeFinder;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Vusys\Exocomp\Analysis\Analyser;
use Vusys\Exocomp\Analysis\Declaration;
use Vusys\Exocomp\Analysis\FileAnalysis;
use Vusys\Exocomp\Analysis\LiteralValue;
use Vusys\Exocomp\Console\CommandContext;
use Vusys\Exocomp\Console\ExocompCommand;
use Vusys\Exocomp\Index\SymbolRecord;
use Vusys\Exocomp\Inference\MemberElsewhere;
use Vusys\Exocomp\Inference\ReferenceScanner;
use Vusys\Exocomp\Output\Result;
use Vusys\Exocomp\Output\Row;
use Vusys\Exocomp\Project\SymbolNotFound;
use Vusys\Exocomp\Render\SkeletonRenderer;
use Vusys\Exocomp\Source\ParseFailure;
use Vusys\Exocomp\Source\SourceFile;
use Vusys\Exocomp\Symbol\Confidence;
use Vusys\Exocomp\Symbol\FileAddress;
use Vusys\Exocomp\Symbol\SymbolKind;
use Vusys\Exocomp\Symbol\SymbolRef;

/**
 * The exact source of a named element.
 *
 * This is the command that replaces the find-then-read dance —
 * `sed -n "$(grep -n 'function foo(' F | cut -d: -f1),+6p" F` — three tools composed to answer
 * "show me this method", with the body length as a guess. When the guess is short the answer is
 * silently truncated, and a truncated method body reads as a complete one. The span here comes
 * from the parser, so it is exact.
 *
 * It takes several addresses at once because the dance it replaces has a second step. Reading
 * `members` and then wanting three of them back was costing three round trips against one
 * `sed` range, so the range won — and a range is only correct while every member you want sits
 * in one file, contiguously, with nothing else in between. Addresses have neither constraint:
 * they need not share a class, so a member and the parent member it overrides can be read
 * together even though no single line range contains both.
 */
#[AsCommand(name: 'show', description: 'Print the exact source of a named class, method, property or constant')]
final class ShowCommand extends ExocompCommand
{
    /** @var list<string> */
    private array $notes = [];

    protected function configure(): void
    {
        parent::configure();

        $this
            ->addArgument('symbol', InputArgument::OPTIONAL | InputArgument::IS_ARRAY, 'One or more symbol addresses, e.g. "App\Services\OrderService::handle" (omit only with --at)')
            ->addOption('without-bodies', null, InputOption::VALUE_NONE, 'Print the declaration skeleton instead of the full source')
            ->addOption('at', null, InputOption::VALUE_REQUIRED, 'Print the declaration covering a position, as file.php:line')
            ->setHelp(<<<'HELP'
            Addresses are names:

              <fqn>                 a class, interface, trait, enum or function
              <fqn>::method         a method (case-insensitive, as PHP is)
              <fqn>::$property      a property, including a promoted one
              <fqn>::CONSTANT       a class constant or enum case

            A file may stand in for the name, for when the path is what you have:

              <path>.php::method    the member of whatever class that file declares
              <path>.php            the class the file declares, if it declares only one

            This is the way in to a Laravel migration, which is an anonymous class and so has
            no name to address it by at all. The member half is still a name, so the address
            survives edits above it.

            Several addresses may be given at once, and are printed in the order asked for:

              exocomp show 'App\Models\Order::place' 'App\Models\Order::cancel'

            They need not share a class, so a method and the parent method it overrides can be
            read side by side. Any that cannot be found are listed at the end rather than
            stopping the others, and the exit code is 1 if even one is missing — a partial
            answer must not read as a whole one.

            `--at database/migrations/2024_01_01_create_orders.php:31` prints the innermost
            declaration covering that line, for a position that came out of a stack trace or a
            diff. A position is not an address and does not survive an edit, so it is a flag
            rather than an argument form.

            Exit codes: 0 all found, 1 one or more not found, 2 error.
            HELP);
    }

    protected function answer(InputInterface $input, CommandContext $context): Result
    {
        $addresses = $this->stringListArgument($input, 'symbol');
        $skeleton = $this->boolOption($input, 'without-bodies');
        $at = $this->stringOption($input, 'at');

        if ($at !== null) {
            return $this->atPosition($at, $skeleton, $context);
        }

        if ($addresses === []) {
            throw new InvalidArgumentException('show wants at least one address, or --at file.php:line');
        }

        // An unsaved buffer, standing in for the file at --as: the address is looked for in it.
        if ($this->readingStdin($input)) {
            return $this->fromStdin($addresses[0], $skeleton, $this->stdinFile($input, $context));
        }

        // One address keeps the answer it has always had: a lone header and body, and a single
        // JSON object rather than a stream of one. A caller that asks the old question must not
        // have to parse a new shape because the command grew a second address.
        if (count($addresses) === 1) {
            $found = $this->find($addresses[0], $skeleton, $context);

            return new Result(
                header: $found['header'],
                body: $found['source'],
                notes: [...$this->notes, ...$context->locator->notes()],
                object: $found['object'],
            );
        }

        $blocks = [];
        $objects = [];
        $missing = [];
        $placed = false;

        foreach ($addresses as $address) {
            try {
                $found = $this->find($address, $skeleton, $context);
            } catch (SymbolNotFound $notFound) {
                // One bad address does not withdraw the others. The reason travels with the
                // row, including the namespace hint, because the commonest miss by far is a
                // real class asked for under a guessed namespace.
                $elsewhere = $this->declaredElsewhere($context, $notFound->address);
                $placed = $placed || $elsewhere !== [];

                $missing[] = new Row(
                    file: '',
                    line: null,
                    column: null,
                    fqn: null,
                    kind: null,
                    text: $address,
                    confidence: Confidence::Textual,
                    note: implode(' — ', [$notFound->getMessage(), ...$elsewhere]),
                );

                continue;
            }

            $blocks[] = $found['header']."\n".$found['source'];
            $objects[] = $found['object'];
        }

        return new Result(
            header: $this->summary(count($addresses), count($missing)),
            body: $blocks === [] ? null : implode("\n\n", $blocks),
            unresolvedTitle: 'not found',
            unresolved: $missing,
            notes: [...$this->notes, ...$this->worthSaying($context->locator->notes(), $placed)],
            found: $missing === [],
            objects: $objects,
        );
    }

    /**
     * The same suppression the single-address path applies in `ExocompCommand::execute()`: a
     * content scan cannot place a class that is not there under the name asked for, so advising
     * one alongside the real namespace sends the reader past the answer to a dead end.
     *
     * @param  list<string>  $notes
     * @return list<string>
     */
    private function worthSaying(array $notes, bool $placed): array
    {
        if (! $placed) {
            return $notes;
        }

        return array_values(array_filter(
            $notes,
            static fn (string $note): bool => ! str_contains($note, 'content scan of the source roots'),
        ));
    }

    private function summary(int $asked, int $missing): string
    {
        if ($missing === 0) {
            return sprintf('%d addresses', $asked);
        }

        return sprintf('%d of %d addresses found', $asked - $missing, $asked);
    }

    /**
     * @return array{header: string, source: string, object: array<string, string|int|bool|null>}
     *
     * @throws SymbolNotFound
     */
    private function find(string $address, bool $skeleton, CommandContext $context): array
    {
        $inFile = FileAddress::parse($address, fn (string $path): ?string => $this->resolvePath($path, $context));

        if ($inFile instanceof FileAddress) {
            $analysis = $context->locator->analyse($inFile->path);

            return $this->rendered($this->inFile($inFile, $analysis), $analysis->file, $skeleton);
        }

        $ref = SymbolRef::parse($address);
        $owner = $context->locator->locateOwner($ref);

        if (! $ref->hasMember()) {
            return $this->rendered($owner->declaration, $owner->analysis->file, $skeleton);
        }

        $member = $owner->declaration->member((string) $ref->member, $ref->memberIsProperty);

        if ($member instanceof Declaration) {
            return $this->rendered($member, $owner->analysis->file, $skeleton);
        }

        // `$order->shippingAddress` and `$order->shipping_address` are what Eloquent serves from
        // `getShippingAddressAttribute()`, an `Attribute`-returning `shippingAddress()`, or a
        // relation method of that name. Tried before same-named members of unrelated classes.
        $accessor = $this->eloquentAccessor($owner->declaration, (string) $ref->member);

        if ($accessor instanceof Declaration) {
            $this->notes[] = sprintf('%s is not a declared member; Eloquent reads it through %s(), by naming convention', $ref->member, $accessor->name);

            return $this->rendered($accessor, $owner->analysis->file, $skeleton);
        }

        [$elsewhere, $file] = $this->declaredOnAnother(SymbolRef::parse($owner->declaration->fqn.'::'.($ref->memberIsProperty ? '$' : '').$ref->member), $context);

        return $this->rendered($elsewhere, $file, $skeleton);
    }

    private function eloquentAccessor(Declaration $class, string $name): ?Declaration
    {
        $studly = str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $name)));

        foreach (['get'.$studly.'Attribute', lcfirst($studly)] as $candidate) {
            $method = $class->member($candidate, false);

            if ($method instanceof Declaration && $method->kind === SymbolKind::Method && ($candidate !== $name)) {
                return $method;
            }
        }

        return null;
    }

    /**
     * A member the named class does not declare, found where it is declared.
     *
     * The index is read as it stands and never built for this, the same rule the namespace
     * correction follows: a command that answers from one file must not acquire a corpus to
     * improve a miss. A cold cache therefore says only what the file says.
     *
     * @return array{Declaration, SourceFile}
     *
     * @throws SymbolNotFound
     */
    private function declaredOnAnother(SymbolRef $ref, CommandContext $context): array
    {
        $miss = sprintf('%s declares no member "%s"', $ref->owner, (string) $ref->member);

        try {
            $found = new MemberElsewhere($context->index()->graph(validate: false))->find($ref);
        } catch (RuntimeException) {
            $found = ['answer' => null, 'reachable' => false, 'note' => null];
        }

        $answer = $found['answer'];

        if ($answer instanceof SymbolRecord && $answer->owner !== null) {
            try {
                $declaring = $context->locator->locateOwner(SymbolRef::parse($answer->owner));
                $member = $declaring->declaration->member($answer->name, $answer->kind === SymbolKind::Property);

                if ($member instanceof Declaration) {
                    $this->notes[] = (string) $found['note'];

                    return [$member, $declaring->analysis->file];
                }
            } catch (SymbolNotFound) {
                // The index remembered a declaration the working tree no longer has; report
                // the miss as asked rather than an answer from a memory of the tree.
            }
        }

        throw new SymbolNotFound((string) $ref, $found['note'] === null
            ? sprintf('%s, nor does anything the index knows of (try: exocomp locate %s)', $miss, (string) $ref->member)
            : $miss.' — '.$found['note']);
    }

    /**
     * The declaration a file address picks out.
     *
     * @throws SymbolNotFound
     */
    private function inFile(FileAddress $address, FileAnalysis $analysis): Declaration
    {
        if (! $address->hasMember()) {
            $classLikes = $analysis->everyClassLike();

            if (count($classLikes) === 1) {
                return $classLikes[0];
            }

            throw new SymbolNotFound((string) $address, $classLikes === []
                ? sprintf('%s declares no class, interface, trait or enum', $address->path)
                : sprintf(
                    '%s declares %d class-likes, so the path alone does not say which — name one (%s), or use: exocomp outline %s',
                    $address->path,
                    count($classLikes),
                    implode(', ', array_map(static fn (Declaration $d): string => $d->fqn, array_slice($classLikes, 0, 3))),
                    $address->path,
                ));
        }

        $found = $analysis->membersNamed((string) $address->member, $address->memberIsProperty);

        if (count($found) === 1) {
            return $found[0];
        }

        throw new SymbolNotFound((string) $address, $found === []
            ? sprintf('%s declares no member "%s" (it may be inherited — try: exocomp members)', $address->path, (string) $address->member)
            : sprintf(
                '%s declares "%s" %d times, at lines %s — use --at %s:<line> to pick one',
                $address->path,
                (string) $address->member,
                count($found),
                implode(', ', array_map(static fn (Declaration $d): string => (string) $d->spanStartLine, $found)),
                $address->path,
            ));
    }

    /**
     * The innermost declaration covering a `file.php:line` position.
     *
     * @throws SymbolNotFound
     */
    private function atPosition(string $position, bool $skeleton, CommandContext $context): Result
    {
        // Greedy up to the last colon, so a path that itself contains one still splits where
        // the line number begins. Every record this tool prints starts `file:line:col`, so a
        // trailing column is accepted, and ignored, when the file is there without it.
        if (preg_match('/^(.*):(\d+)$/', $position, $match) !== 1) {
            throw new InvalidArgumentException(sprintf('--at wants a position as file.php:line, not "%s"', $position));
        }

        if (preg_match('/^(.*):(\d+):(\d+)$/', $position, $withColumn) === 1 && $this->resolvePath($withColumn[1], $context) !== null) {
            $match = [$withColumn[0], $withColumn[1], $withColumn[2]];
        }

        $line = (int) $match[2];
        $path = $this->resolvePath($match[1], $context);

        if ($path === null) {
            throw new RuntimeException(sprintf('no such file: "%s"', $match[1]));
        }

        $analysis = $context->locator->analyse($path);
        $declaration = $analysis->declarationSpanning($line);

        if (! $declaration instanceof Declaration && ($closure = $this->closureAt($analysis->file, $line)) instanceof Result) {
            return $closure;
        }

        if (! $declaration instanceof Declaration) {
            throw new SymbolNotFound($position, sprintf(
                '%s:%d is not inside any declaration — the file has %d line%s',
                $analysis->file->displayPath,
                $line,
                $analysis->file->lineCount(),
                $analysis->file->lineCount() === 1 ? '' : 's',
            ));
        }

        $found = $this->rendered($declaration, $analysis->file, $skeleton);

        return new Result(
            header: $found['header'],
            body: $found['source'],
            notes: $context->locator->notes(),
            object: $found['object'],
        );
    }

    /**
     * @throws SymbolNotFound
     */
    private function fromStdin(string $address, bool $skeleton, FileAnalysis $analysis): Result
    {
        $ref = SymbolRef::parse($address);
        $owner = $analysis->classLike($ref->owner) ?? $analysis->function($ref->owner);
        $declaration = $owner instanceof Declaration && $ref->hasMember() ? $owner->member((string) $ref->member, $ref->memberIsProperty) : $owner;

        if (! $declaration instanceof Declaration) {
            throw new SymbolNotFound($address, sprintf('%s is not declared in the source on standard input (%s)', $address, $analysis->file->displayPath));
        }

        $found = $this->rendered($declaration, $analysis->file, $skeleton);

        return new Result(header: $found['header'], body: $found['source'], object: $found['object']);
    }

    /**
     * A closure outside any declaration: a Pest test, a route or console closure. A stack trace
     * from a Pest failure points into one, and "not inside any declaration" answered none of them.
     */
    private function closureAt(SourceFile $file, int $line): ?Result
    {
        try {
            [, $statements] = new Analyser()->analyseWithAst($file);
        } catch (ParseFailure) {
            return null;
        }

        $finder = new NodeFinder;
        $best = null;

        foreach ($finder->find($statements, static fn (Node $node): bool => $node instanceof Closure || $node instanceof ArrowFunction) as $closure) {
            if ($closure->getStartLine() <= $line && $closure->getEndLine() >= $line
                && ($best === null || $closure->getStartLine() >= $best->getStartLine())) {
                $best = $closure;
            }
        }

        if ($best === null) {
            return null;
        }

        // `it('refunds', function () { … })`: the description is how the test is known.
        $described = null;

        foreach ($finder->findInstanceOf($statements, FuncCall::class) as $call) {
            $arguments = Analyser::arguments($call);

            if (($arguments[1]->value ?? null) === $best && $call->name instanceof Name && ($label = LiteralValue::scalar($arguments[0]->value)) !== null) {
                $described = sprintf('%s(%s)', $call->name->toString(), var_export($label, true));
            }
        }

        $source = $file->slice($best->getStartFilePos(), $best->getEndFilePos());

        return new Result(
            header: sprintf('%s:%d-%d  %s%s', $file->displayPath, $best->getStartLine(), $best->getEndLine(), ReferenceScanner::closureName(null, $file->displayPath, $best->getStartLine()), $described === null ? '' : '  '.$described),
            body: $source,
        );
    }

    /**
     * A written path made absolute, tried against the working directory and then the project.
     *
     * Both have to work. An interactive caller types a path relative to where it is standing; an
     * agent passing `--project` writes one relative to the project, because that is the spelling
     * every answer this tool gives reports and the spelling a diff or a stack trace uses.
     */
    private function resolvePath(string $written, CommandContext $context): ?string
    {
        foreach ([$written, $context->project->path($written)] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return array{header: string, source: string, object: array<string, string|int|bool|null>}
     */
    private function rendered(Declaration $declaration, SourceFile $file, bool $skeleton): array
    {
        $source = $skeleton
            ? SkeletonRenderer::render($declaration)
            : $file->lines($declaration->spanStartLine, $declaration->endLine);

        return [
            'header' => sprintf(
                '%s:%d-%d  %s',
                $file->displayPath,
                $declaration->spanStartLine,
                $declaration->endLine,
                $declaration->fqn,
            ),
            'source' => $source,
            'object' => [
                'file' => $file->displayPath,
                'line' => $declaration->spanStartLine,
                'col' => $declaration->startColumn,
                'endLine' => $declaration->endLine,
                'fqn' => $declaration->fqn,
                'kind' => $declaration->kind->value,
                'confidence' => Confidence::Resolved->value,
                'source' => $source,
            ],
        ];
    }
}

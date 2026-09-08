<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Console\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Vusys\Exocomp\Analysis\Declaration;
use Vusys\Exocomp\Console\CommandContext;
use Vusys\Exocomp\Console\ExocompCommand;
use Vusys\Exocomp\Output\Result;
use Vusys\Exocomp\Project\LocatedDeclaration;
use Vusys\Exocomp\Project\SymbolNotFound;
use Vusys\Exocomp\Render\SkeletonRenderer;
use Vusys\Exocomp\Symbol\Confidence;
use Vusys\Exocomp\Symbol\SymbolRef;

/**
 * The exact source of a named element.
 *
 * This is the command that replaces the find-then-read dance —
 * `sed -n "$(grep -n 'function foo(' F | cut -d: -f1),+6p" F` — three tools composed to answer
 * "show me this method", with the body length as a guess. When the guess is short the answer is
 * silently truncated, and a truncated method body reads as a complete one. The span here comes
 * from the parser, so it is exact.
 */
#[AsCommand(name: 'show', description: 'Print the exact source of a named class, method, property or constant')]
final class ShowCommand extends ExocompCommand
{
    protected function configure(): void
    {
        parent::configure();

        $this
            ->addArgument('symbol', InputArgument::REQUIRED, 'Symbol address, e.g. "App\Services\OrderService::handle"')
            ->addOption('without-bodies', null, InputOption::VALUE_NONE, 'Print the declaration skeleton instead of the full source')
            ->setHelp(<<<'HELP'
            Addresses are names, never file positions:

              <fqn>                 a class, interface, trait, enum or function
              <fqn>::method         a method (case-insensitive, as PHP is)
              <fqn>::$property      a property, including a promoted one
              <fqn>::CONSTANT       a class constant or enum case

            Exit codes: 0 found, 1 not found, 2 error.
            HELP);
    }

    protected function answer(InputInterface $input, CommandContext $context): Result
    {
        $ref = SymbolRef::parse($this->stringArgument($input, 'symbol') ?? '');
        $owner = $context->locator->locateOwner($ref);
        $declaration = $this->resolve($ref, $owner);
        $file = $owner->analysis->file;

        $source = $this->boolOption($input, 'without-bodies')
            ? SkeletonRenderer::render($declaration)
            : $file->lines($declaration->spanStartLine, $declaration->endLine);

        $header = sprintf(
            '%s:%d-%d  %s',
            $file->displayPath,
            $declaration->spanStartLine,
            $declaration->endLine,
            $declaration->fqn,
        );

        return new Result(
            header: $header,
            body: $source,
            notes: $context->locator->notes(),
            object: [
                'file' => $file->displayPath,
                'line' => $declaration->spanStartLine,
                'col' => $declaration->startColumn,
                'endLine' => $declaration->endLine,
                'fqn' => $declaration->fqn,
                'kind' => $declaration->kind->value,
                'confidence' => Confidence::Resolved->value,
                'source' => $source,
            ],
        );
    }

    private function resolve(SymbolRef $ref, LocatedDeclaration $owner): Declaration
    {
        if (! $ref->hasMember()) {
            return $owner->declaration;
        }

        $member = $owner->declaration->member((string) $ref->member, $ref->memberIsProperty);

        if ($member instanceof Declaration) {
            return $member;
        }

        // Deliberately not a search of parents or traits: this build resolves only what a
        // single file declares, and answering from a guess about inheritance would be the
        // confidently-wrong failure this tool exists to avoid.
        throw new SymbolNotFound((string) $ref, sprintf(
            '%s declares no member "%s" (it may be inherited — try: exocomp members %s)',
            $owner->declaration->fqn,
            (string) $ref->member,
            $owner->declaration->fqn,
        ));
    }
}

<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Console\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Vusys\Exocomp\Analysis\Declaration;
use Vusys\Exocomp\Console\CommandContext;
use Vusys\Exocomp\Console\ExocompCommand;
use Vusys\Exocomp\Output\Result;
use Vusys\Exocomp\Output\Row;
use Vusys\Exocomp\Output\Section;
use Vusys\Exocomp\Symbol\SymbolRef;

/**
 * The shape of a file at a small fraction of its tokens.
 *
 * This replaces the recurring `grep -n 'public function\|#\[Test\]\|…' F | sed -n '1,200p'`
 * idiom, and the 737 measured searches for a declaration. Because its whole value is being
 * cheap, it is ruthless: one dense line per member, parameter types without names, short type
 * names, and no bodies at all.
 */
#[AsCommand(name: 'outline', description: 'List the declarations in a file or class: signatures, visibility, return types, line spans')]
final class OutlineCommand extends ExocompCommand
{
    protected function configure(): void
    {
        parent::configure();

        $this->addArgument('target', InputArgument::REQUIRED, 'A file path, or the name of a class, interface, trait or enum');
    }

    protected function answer(InputInterface $input, CommandContext $context): Result
    {
        $target = $this->stringArgument($input, 'target') ?? '';

        if (is_file($target)) {
            $analysis = $context->locator->analyse($target);
            $declarations = $analysis->declarations;
        } else {
            $located = $context->locator->locateOwner(SymbolRef::parse($target));
            $analysis = $located->analysis;
            $declarations = [$located->declaration];
        }

        $file = $analysis->file;
        $rows = [];

        // A file holding several declarations may hold several namespaces — `Braces.php` in the
        // corpus declares `Inhabitant` twice. Short names would then be ambiguous in the very
        // output that exists to disambiguate them.
        $qualify = count($declarations) > 1;

        foreach ($declarations as $declaration) {
            // The declaration's own line carries what the header cannot: modifiers, what it
            // extends, and what it implements — 32 of the measured searches were hunting
            // exactly those relationships by hand.
            $rows[] = $this->row($file->displayPath, $declaration, $qualify);

            foreach ($declaration->members as $member) {
                $rows[] = $this->row($file->displayPath, $member, false);
            }
        }

        [$kept, $elided] = $context->budget->apply($rows);

        return new Result(
            header: $this->header($file->displayPath, $declarations),
            sections: [new Section('', $kept)],
            elided: $elided,
            notes: $context->locator->notes(),
        );
    }

    /**
     * @param  list<Declaration>  $declarations
     */
    private function header(string $path, array $declarations): string
    {
        if (count($declarations) === 1) {
            return $path.'  '.$declarations[0]->fqn;
        }

        return sprintf('%s  (%d declarations)', $path, count($declarations));
    }

    private function row(string $path, Declaration $declaration, bool $qualify): Row
    {
        $text = $qualify
            ? str_replace(' '.$declaration->name, ' '.$declaration->fqn, $declaration->signature)
            : $declaration->signature;

        if ($declaration->isPromoted) {
            $text .= '  (promoted)';
        }

        return new Row(
            file: $path,
            line: $declaration->startLine,
            column: $declaration->startColumn,
            fqn: $declaration->fqn,
            kind: $declaration->kind->value,
            text: $text,
            extra: $declaration->isPromoted ? ['promoted' => true] : [],
        );
    }
}

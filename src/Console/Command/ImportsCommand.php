<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Console\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Vusys\Exocomp\Analysis\Import;
use Vusys\Exocomp\Console\CommandContext;
use Vusys\Exocomp\Console\ExocompCommand;
use Vusys\Exocomp\Output\Result;
use Vusys\Exocomp\Output\Row;
use Vusys\Exocomp\Output\Section;

/**
 * The `use` map of a file, with aliases resolved.
 *
 * Aliases are the half a textual search cannot see. `use Acme\Http\Client as HttpClient` means
 * every later `HttpClient` refers to `Acme\Http\Client`, and no grep for either name finds both
 * ends of that — which is the same trap that makes a `sed`-driven rename unsafe.
 */
#[AsCommand(name: 'imports', description: 'List a file\'s use statements with aliases resolved')]
final class ImportsCommand extends ExocompCommand
{
    private const int KIND_WIDTH = 8;

    protected function configure(): void
    {
        parent::configure();

        $this->addArgument('target', InputArgument::OPTIONAL, 'A file path, or the name of a class declared in the file (omit with --stdin)');
    }

    protected function answer(InputInterface $input, CommandContext $context): Result
    {
        $target = $this->stringArgument($input, 'target') ?? '';
        $analysis = $this->readingStdin($input)
            ? $this->stdinFile($input, $context)
            : $this->targetFile($target, $context);
        $path = $analysis->file->displayPath;

        $rows = array_map(
            static fn (Import $import): Row => self::row($path, $import),
            $analysis->imports,
        );

        [$kept, $elided] = $context->budget->apply($rows);

        return new Result(
            header: $this->header($path, $analysis->namespaces, count($rows)),
            sections: [new Section('', $kept)],
            elided: $elided,
            notes: $context->locator->notes(),
        );
    }

    /**
     * @param  list<string>  $namespaces
     */
    private function header(string $path, array $namespaces, int $count): string
    {
        $declared = array_filter($namespaces, static fn (string $namespace): bool => $namespace !== '');
        $label = $declared === [] ? '(global namespace)' : implode(', ', $declared);

        return sprintf('%s  %s  (%d imports)', $path, $label, $count);
    }

    private static function row(string $path, Import $import): Row
    {
        $name = $import->isAliased ? $import->fqn.' as '.$import->alias : $import->fqn;

        return new Row(
            file: $path,
            line: $import->line,
            column: $import->column,
            fqn: $import->fqn,
            kind: $import->kind->value,
            text: str_pad($import->kind->value, self::KIND_WIDTH).'  '.$name,
            extra: ['alias' => $import->alias, 'aliased' => $import->isAliased],
        );
    }
}

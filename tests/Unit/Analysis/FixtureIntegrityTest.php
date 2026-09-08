<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Analysis;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Tests\Support\Cli;

/**
 * Guards the corpus against its own toolchain.
 *
 * Most of the fixture corpus is deliberately awkward code, and the standard toolchain wants to
 * fix it: a formatter that tidies a naming trap, or a refactorer that "modernises" a construct,
 * silently deletes the thing under test while every other test still passes. Pint, Rector and
 * PHPStan are all configured to leave `tests/Fixtures` alone; this asserts that they did, by
 * checking the constructs are still present as bytes.
 *
 * It is not a substitute for the semantic tests. It catches the specific failure where a
 * fixture stops encoding what its name says it encodes.
 */
final class FixtureIntegrityTest extends TestCase
{
    #[DataProvider('constructs')]
    public function test_the_corpus_still_contains_the_construct_it_exists_to_encode(string $file, string $construct): void
    {
        $contents = file_get_contents(Cli::corpusFile($file));

        $this->assertIsString($contents, "unreadable fixture: {$file}");
        $this->assertStringContainsString($construct, $contents, "{$file} no longer contains: {$construct}");
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function constructs(): iterable
    {
        yield 'property hook, get only' => ['src/TierA/Modern.php', 'public string $shout {'];
        yield 'property hook, get and set' => ['src/TierA/Modern.php', 'set (string $value)'];
        yield 'asymmetric visibility' => ['src/TierA/Modern.php', 'public private(set) int $version'];
        yield 'promoted asymmetric visibility' => ['src/TierA/Modern.php', 'public private(set) bool $sealed'];
        yield 'disjunctive normal form type' => ['src/TierA/Types.php', '(Countable&Stringable)|null'];
        yield 'never return type' => ['src/TierA/Types.php', ': never'];
        yield 'aliased import' => ['src/TierB/Aliases.php', 'use Fixtures\TierA\Support\Repository as Store;'];
        yield 'group use' => ['src/TierB/Aliases.php', 'use Fixtures\TierB\Deep\{Alpha, Beta as Second};'];
        yield 'function import' => ['src/TierB/Aliases.php', 'use function '];
        yield 'constant import' => ['src/TierB/Aliases.php', 'use const '];
        yield 'case-sensitive property pair' => ['src/TierB/Casing.php', 'public string $Value'];
        yield 'brace-syntax namespace' => ['src/TierB/Braces.php', 'namespace Fixtures\TierB\First {'];
        yield 'conditionally declared class' => ['src/TierB/Conditional.php', 'if (! class_exists('];
        yield 'relative namespace reference' => ['src/TierB/Relative.php', 'extends Deep\Alpha'];
        yield 'deliberately unparseable' => ['src/TierF/Unparseable.php', '$this->'];
    }

    public function test_the_unparseable_fixture_is_still_unparseable(): void
    {
        $run = Cli::inCorpus(['outline', 'src/TierF/Unparseable.php']);

        // A fixture that quietly became valid PHP would take the degradation path out of the
        // suite without failing anything.
        $this->assertSame(2, $run->exitCode);
    }
}

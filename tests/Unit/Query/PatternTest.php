<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Query;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Query\Pattern;

final class PatternTest extends TestCase
{
    #[DataProvider('patterns')]
    public function test_it_parses_the_shapes_a_caller_would_write(string $pattern): void
    {
        $this->assertSame($pattern, Pattern::parse($pattern)->source);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function patterns(): iterable
    {
        yield 'method call' => ['$X->firstOrCreate($KEYS, $VALUES)'];
        yield 'function call with any arguments' => ['writeMetric(...)'];
        yield 'static call' => ['Order::create($ATTRIBUTES)'];
        yield 'instantiation' => ['new SalesforceClient(...)'];
        yield 'assignment' => ['$X = $Y->find($ID)'];
        yield 'declaration' => ['function _M() {}'];
        yield 'constant fetch' => ['self::DEFAULT_LABEL'];
        yield 'any method name' => ['$R->_M($A)'];
    }

    public function test_it_reports_metavariables(): void
    {
        $this->assertSame(['X', 'KEYS', 'VALUES'], Pattern::parse('$X->firstOrCreate($KEYS, $VALUES)')->metavariables());
    }

    #[DataProvider('notPatterns')]
    public function test_it_rejects_what_is_not_php(string $pattern, string $expected): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches($expected);

        Pattern::parse($pattern);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function notPatterns(): iterable
    {
        yield 'empty' => ['', '/empty pattern/'];
        yield 'nonsense' => ['$x ->-> y', '/not parseable PHP/'];
        yield 'two statements' => ['$a = 1; $b = 2;', '/single expression or declaration/'];
    }

    /**
     * The trap that made an earlier design unusable: with all-caps identifiers treated as
     * metavariables, every constant reference in PHP would be a wildcard. `Foo::MAX_DEPTH`
     * would silently match `Foo::ANYTHING`, and a pattern that matches more than it says is
     * exactly the confidently-wrong failure this tool exists to avoid.
     */
    public function test_a_constant_reference_is_literal_not_a_wildcard(): void
    {
        $pattern = Pattern::parse('self::MAX_DEPTH');

        $this->assertSame([], $pattern->metavariables());
    }

    /**
     * `_M` sits where a variable is not legal PHP — a method name — which is the whole reason
     * the second spelling exists.
     */
    public function test_an_underscore_prefixed_name_is_a_metavariable(): void
    {
        $this->assertSame(['R', 'M', 'A'], Pattern::parse('$R->_M($A)')->metavariables());
        $this->assertSame(['M'], Pattern::parse('function _M() {}')->metavariables());
    }
}

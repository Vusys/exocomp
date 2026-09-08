<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Symbol;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Symbol\SymbolRef;

final class SymbolRefTest extends TestCase
{
    public function test_a_bare_name_addresses_a_class(): void
    {
        $ref = SymbolRef::parse('App\Models\Order');

        $this->assertSame('App\Models\Order', $ref->owner);
        $this->assertFalse($ref->hasMember());
        $this->assertNull($ref->member);
    }

    public function test_it_addresses_a_method(): void
    {
        $ref = SymbolRef::parse('App\Models\Order::save');

        $this->assertSame('App\Models\Order', $ref->owner);
        $this->assertSame('save', $ref->member);
        $this->assertFalse($ref->memberIsProperty);
    }

    public function test_a_dollar_sign_addresses_a_property(): void
    {
        $ref = SymbolRef::parse('App\Models\Order::$table');

        $this->assertSame('table', $ref->member);
        $this->assertTrue($ref->memberIsProperty);
    }

    /**
     * A caller pasting an address out of source or out of an error message should not have to
     * clean it up first: `\Foo\Bar`, `Foo\\Bar` (shell-escaped) and `Foo\Bar::baz()` all name
     * something real and all must work.
     */
    #[DataProvider('equivalentSpellings')]
    public function test_it_normalises_the_spellings_that_arrive_from_real_use(string $address, string $expected): void
    {
        $this->assertSame($expected, (string) SymbolRef::parse($address));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function equivalentSpellings(): iterable
    {
        yield 'leading backslash' => ['\App\Models\Order', 'App\Models\Order'];
        yield 'shell-escaped separators' => ['App\\\\Models\\\\Order', 'App\Models\Order'];
        yield 'call syntax' => ['App\Models\Order::save()', 'App\Models\Order::save'];
        yield 'surrounding whitespace' => ['  App\Models\Order  ', 'App\Models\Order'];
        yield 'property round trip' => ['App\Models\Order::$table', 'App\Models\Order::$table'];
    }

    #[DataProvider('malformedAddresses')]
    public function test_it_rejects_addresses_it_cannot_mean(string $address): void
    {
        $this->expectException(InvalidArgumentException::class);

        SymbolRef::parse($address);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedAddresses(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace only' => ['   '];
        yield 'no owner' => ['::save'];
        yield 'no member' => ['App\Order::'];
        yield 'two separators' => ['App\Order::inner::save'];
    }
}

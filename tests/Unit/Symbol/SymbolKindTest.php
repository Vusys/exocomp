<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Symbol;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Symbol\SymbolKind;

final class SymbolKindTest extends TestCase
{
    /**
     * Only class-likes own members, and therefore only they are addressable as `Foo::bar`.
     * A function that answered "yes" here would send the member lookup somewhere it cannot go.
     */
    #[DataProvider('kinds')]
    public function test_only_class_likes_own_members(SymbolKind $kind, bool $expected): void
    {
        $this->assertSame($expected, $kind->isClassLike());
    }

    /**
     * @return iterable<string, array{SymbolKind, bool}>
     */
    public static function kinds(): iterable
    {
        yield 'class' => [SymbolKind::ClassType, true];
        yield 'interface' => [SymbolKind::InterfaceType, true];
        yield 'trait' => [SymbolKind::TraitType, true];
        yield 'enum' => [SymbolKind::EnumType, true];
        yield 'function' => [SymbolKind::FunctionDecl, false];
        yield 'method' => [SymbolKind::Method, false];
        yield 'property' => [SymbolKind::Property, false];
        yield 'constant' => [SymbolKind::Constant, false];
        yield 'enum case' => [SymbolKind::EnumCase, false];
    }
}

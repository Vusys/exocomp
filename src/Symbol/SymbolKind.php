<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Symbol;

enum SymbolKind: string
{
    case ClassType = 'class';
    case InterfaceType = 'interface';
    case TraitType = 'trait';
    case EnumType = 'enum';
    case FunctionDecl = 'function';
    case Method = 'method';
    case Property = 'property';
    case Constant = 'constant';
    case EnumCase = 'enum-case';

    /**
     * Whether this kind can own members — i.e. whether it is addressable as `Foo::bar`.
     */
    public function isClassLike(): bool
    {
        return match ($this) {
            self::ClassType, self::InterfaceType, self::TraitType, self::EnumType => true,
            default => false,
        };
    }
}

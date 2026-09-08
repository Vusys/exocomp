<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Analysis;

enum ImportKind: string
{
    case ClassLike = 'class';
    case FunctionName = 'function';
    case ConstantName = 'const';
}

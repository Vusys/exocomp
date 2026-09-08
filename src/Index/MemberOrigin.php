<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Index;

enum MemberOrigin: string
{
    case Own = 'own';
    case Trait_ = 'trait';
    case Inherited = 'inherited';
    case Interface_ = 'interface';
}

<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Output;

use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Output\Address;
use Vusys\Exocomp\Output\Counted;

final class CountedTest extends TestCase
{
    public function test_a_count_agrees_with_its_noun(): void
    {
        $this->assertSame('1 read', Counted::of(1, 'read'));
        $this->assertSame('0 reads', Counted::of(0, 'read'));
        $this->assertSame('3 reads', Counted::of(3, 'read'));
        $this->assertSame('2 indexes', Counted::of(2, 'index', 'indexes'));
        $this->assertSame('property', Counted::noun(1, 'property', 'properties'));
    }

    public function test_a_line_of_zero_is_no_line(): void
    {
        $this->assertSame('exocomp-routes.json', Address::of('exocomp-routes.json', 0, 1));
        $this->assertSame('routes/web.php:18:1', Address::of('routes/web.php', 18, 1));
    }
}

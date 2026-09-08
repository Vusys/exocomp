<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Graph;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Graph\Target;

/**
 * What counts as arriving.
 *
 * The destination is the half of `reaches` that decides whether an answer is about the thing
 * that was asked. A matcher that is one character too generous turns "does this endpoint write
 * to Order" into "does this endpoint call anything at all", and the verdict still looks like a
 * verdict.
 */
final class TargetTest extends TestCase
{
    public function test_a_class_and_member_matches_only_that_member(): void
    {
        $target = Target::parse('App\Models\Order::save');

        $this->assertTrue($target->matches('App\Models\Order::save'));
        $this->assertFalse($target->matches('App\Models\Order::delete'));
        $this->assertFalse($target->matches('App\Models\Customer::save'));
    }

    public function test_a_class_on_its_own_matches_any_of_its_members(): void
    {
        $target = Target::parse('App\Models\Order');

        $this->assertTrue($target->matches('App\Models\Order::save'));
        $this->assertTrue($target->matches('App\Models\Order::__construct'));
        $this->assertFalse($target->matches('App\Models\OrderLine::save'));
    }

    public function test_a_leading_double_colon_matches_the_member_on_any_class(): void
    {
        $target = Target::parse('::save');

        $this->assertTrue($target->matches('App\Models\Order::save'));
        $this->assertTrue($target->matches('Illuminate\Database\Eloquent\Model::save'));
        $this->assertFalse($target->matches('App\Models\Order::delete'));
    }

    public function test_a_bare_function_name_is_matchable(): void
    {
        $this->assertTrue(Target::parse('array_map')->matches('array_map'));
        $this->assertFalse(Target::parse('::save')->matches('array_map'));
    }

    public function test_globs_apply_to_either_half(): void
    {
        $this->assertTrue(Target::parse('App\Models\*::save')->matches('App\Models\Order::save'));
        $this->assertTrue(Target::parse('App\Models\Order::delete*')->matches('App\Models\Order::deleteQuietly'));
        $this->assertFalse(Target::parse('App\Models\*::save')->matches('App\Services\Order::save'));
    }

    public function test_matching_folds_case_the_way_php_does(): void
    {
        $this->assertTrue(Target::parse('app\models\ORDER::SAVE')->matches('App\Models\Order::save'));
    }

    public function test_a_leading_backslash_is_the_same_address(): void
    {
        $this->assertTrue(Target::parse('\App\Models\Order::save')->matches('App\Models\Order::save'));
        $this->assertTrue(Target::parse('App\Models\Order::save')->matches('\App\Models\Order::save'));
    }

    public function test_a_trailing_call_parenthesis_is_accepted_as_pasted(): void
    {
        $this->assertTrue(Target::parse('App\Models\Order::save()')->matches('App\Models\Order::save'));
    }

    public function test_an_empty_destination_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Target::parse('   ');
    }

    public function test_more_than_one_separator_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Target::parse('A::b::c');
    }

    public function test_a_separator_with_nothing_on_either_side_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Target::parse('::');
    }
}

<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Graph;

use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Graph\Stop;

/**
 * The identity of a place the walk stopped.
 *
 * Every part of it matters. Two unfollowable calls in one method are two stops; the same call
 * met twice is one. Drop the address from the key and a method with a dozen distinct gaps
 * reports one; drop the declaration and two methods with the same shortcoming collapse into
 * each other. Both directions make the trailing block lie about how much was missed.
 */
final class StopTest extends TestCase
{
    public function test_the_same_call_met_twice_has_one_identity(): void
    {
        $this->assertSame($this->stop()->key(), $this->stop()->key());
    }

    public function test_two_calls_on_one_line_are_two_stops(): void
    {
        $this->assertNotSame($this->stop()->key(), $this->stop(detail: '$other->thing()')->key());
    }

    public function test_two_lines_in_one_file_are_two_stops(): void
    {
        $this->assertNotSame($this->stop()->key(), $this->stop(line: 12)->key());
    }

    public function test_the_same_line_number_in_two_files_are_two_stops(): void
    {
        $this->assertNotSame($this->stop()->key(), $this->stop(path: 'app/Other.php')->key());
    }

    public function test_the_same_call_reached_from_two_declarations_are_two_stops(): void
    {
        $this->assertNotSame($this->stop()->key(), $this->stop(from: 'App\Other::run')->key());
    }

    public function test_the_reason_is_carried_but_is_not_part_of_the_identity(): void
    {
        // Two readings of one call site are one gap. The reason is what to print, not what
        // makes it a separate thing to print.
        $this->assertSame($this->stop()->key(), $this->stop(reason: 'something else entirely')->key());
    }

    private function stop(
        string $from = 'App\Services\Thing::run',
        string $path = 'app/Services/Thing.php',
        int $line = 11,
        string $detail = '$unknown->order()',
        string $reason = 'receiver type unknown',
    ): Stop {
        return new Stop($from, Stop::UNRESOLVED, $path, $line, 9, $reason, $detail);
    }
}

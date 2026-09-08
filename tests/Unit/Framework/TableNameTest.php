<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Framework;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Framework\Laravel\TableName;

/**
 * The one part of the profile that is a convention rather than a reading.
 *
 * The cases below are the ones a hand-rolled pluraliser gets wrong, which is why this defers to
 * the framework's own inflector: uncountables, already-plural names, and the `-f` rule that
 * turns `Chef` into `cheves`. They are exactly the names nobody can guess, and therefore exactly
 * the ones someone is looking up.
 */
final class TableNameTest extends TestCase
{
    #[DataProvider('conventions')]
    public function test_the_table_name_follows_the_frameworks_convention(string $fqn, string $expected): void
    {
        $this->assertSame($expected, TableName::forClass($fqn));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function conventions(): iterable
    {
        yield 'simple' => ['App\Models\Order', 'orders'];
        yield 'only the last word is pluralised' => ['App\Models\OrderLineItem', 'order_line_items'];
        yield 'irregular' => ['App\Models\Person', 'people'];
        yield 'consonant y' => ['App\Models\Category', 'categories'];
        yield 'sibilant' => ['App\Models\Box', 'boxes'];
        yield 'already plural' => ['App\Models\Settings', 'settings'];
        yield 'already plural compound' => ['App\Models\TeamsToMembers', 'teams_to_members'];
        yield 'uncountable' => ['App\Models\Metadata', 'metadata'];
        yield 'f is not always ves' => ['App\Models\HeadChef', 'head_chefs'];
        yield 'f sometimes is' => ['App\Models\Leaf', 'leaves'];
        yield 'status keeps its es' => ['App\Models\Status', 'statuses'];
        yield 'runs of capitals split, as Str::snake does' => ['App\Models\APIToken', 'a_p_i_tokens'];
        yield 'a name with no namespace' => ['Order', 'orders'];
    }

    public function test_a_name_that_ends_in_something_uninflectable_is_left_alone(): void
    {
        // The framework refuses to pluralise a value that does not end in a word character.
        $this->assertSame('order_', TableName::forClass('App\Models\Order_'));
    }

    public function test_the_frameworks_own_uncountable_additions_are_honoured(): void
    {
        $this->assertSame('related', TableName::plural('related'));
        $this->assertSame('recommended', TableName::plural('recommended'));
    }

    public function test_capitalisation_survives_pluralisation(): void
    {
        // `Person` has to come back as `People`, not `people`, or snake-casing a compound name
        // would lose the word boundary.
        $this->assertSame('People', TableName::plural('Person'));
        $this->assertSame('OrderPeople', TableName::pluralStudly('OrderPerson'));
    }
}

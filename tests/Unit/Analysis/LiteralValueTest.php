<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Analysis;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Analysis\LiteralValue;

/**
 * Reading the value of an expression that has one, and admitting it when there is not.
 *
 * The second half is the important one. Everything the framework profile reports rests on this:
 * a `$table` that is computed, a related class named by a variable, a config value built at
 * runtime. Each of those has to come back as null so the caller can say so, because the
 * alternative is a plausible-looking value that is nobody's actual configuration.
 */
final class LiteralValueTest extends TestCase
{
    public function test_scalars_come_back_as_themselves(): void
    {
        $this->assertSame('orders', $this->scalar("'orders'"));
        $this->assertSame(42, $this->scalar('42'));
        $this->assertSame(1.5, $this->scalar('1.5'));
        $this->assertTrue($this->scalar('true'));
        $this->assertFalse($this->scalar('false'));
        $this->assertNull($this->scalar('null'));
    }

    public function test_anything_computed_comes_back_as_null(): void
    {
        $this->assertNull($this->scalar('$name'));
        $this->assertNull($this->scalar('strtolower("X")'));
        $this->assertNull($this->scalar('1 + 1'));
        $this->assertNull($this->scalar('"a{$b}c"'));
    }

    public function test_a_class_constant_fetch_resolves_to_the_class(): void
    {
        $this->assertSame('App\Models\Order', $this->className('\App\Models\Order::class'));
    }

    public function test_a_string_spelled_like_a_class_is_read_as_one(): void
    {
        // Not the same claim as `::class` — the caller is told which it got — but both name a
        // class, and framework code uses the string form constantly.
        $this->assertSame('App\Models\Order', $this->className("'\\\\App\\\\Models\\\\Order'"));
    }

    public function test_a_string_with_no_separator_is_not_a_class_name(): void
    {
        $this->assertNull($this->className("'orders'"));
    }

    public function test_self_resolves_to_the_class_it_is_written_in_when_one_is_given(): void
    {
        // Returning the literal word would put "self" where a fully-qualified name belongs, and
        // a self-referencing relation would report a class called `self` that does not exist.
        $this->assertSame('App\Models\Order', $this->className('self::class', 'App\Models\Order'));
        $this->assertSame('App\Models\Order', $this->className('static::class', 'App\Models\Order'));
        $this->assertSame('self', $this->className('self::class'));
    }

    public function test_a_constant_other_than_class_is_not_a_class_name(): void
    {
        $this->assertNull($this->className('\App\Models\Order::TABLE'));
    }

    public function test_items_keep_their_lines_and_flag_what_is_not_literal(): void
    {
        $items = LiteralValue::items($this->expression("[\n'a',\n\$b,\n]"));

        $this->assertCount(2, $items);
        $this->assertSame('a', $items[0]['value']);
        $this->assertTrue($items[0]['literal']);
        $this->assertNull($items[1]['value']);
        $this->assertFalse($items[1]['literal']);
        $this->assertNotSame($items[0]['line'], $items[1]['line']);
    }

    public function test_items_of_something_that_is_not_an_array_is_empty(): void
    {
        $this->assertSame([], LiteralValue::items($this->expression("'not an array'")));
        $this->assertSame([], LiteralValue::pairs($this->expression("'not an array'")));
    }

    public function test_pairs_keep_the_key_and_render_the_value(): void
    {
        $pairs = LiteralValue::pairs($this->expression("['status' => \\App\\Enums\\Status::class, 'flag' => true, 'live' => \$x]"));

        $this->assertSame(['status', 'flag', 'live'], array_column($pairs, 'key'));
        $this->assertSame('App\Enums\Status', $pairs[0]['value']);
        $this->assertSame('true', $pairs[1]['value']);
        $this->assertNull($pairs[2]['value']);
    }

    public function test_a_list_without_keys_yields_no_pairs(): void
    {
        $this->assertSame([], LiteralValue::pairs($this->expression("['a', 'b']")));
    }

    private function scalar(string $code): string|int|float|bool|null
    {
        return LiteralValue::scalar($this->expression($code));
    }

    private function className(string $code, ?string $selfFqn = null): ?string
    {
        return LiteralValue::className($this->expression($code), $selfFqn);
    }

    private function expression(string $code): Node\Expr
    {
        $parser = new ParserFactory()->createForNewestSupportedVersion();
        $statements = $parser->parse('<?php $value = '.$code.';');

        $this->assertIsArray($statements);

        $statements = new NodeTraverser(new NameResolver(null, ['replaceNodes' => false]))->traverse($statements);
        $assignment = new NodeFinder()->findFirstInstanceOf($statements, Node\Expr\Assign::class);

        $this->assertInstanceOf(Node\Expr\Assign::class, $assignment);

        return $assignment->expr;
    }
}

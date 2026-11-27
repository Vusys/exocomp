<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Inference;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Tests\Support\Cli;

/**
 * Types a docblock or the code fixes, and names that must never become classes: a generic's
 * element, a class template bound by `@param Box<X>`, keyed destructuring, `@return static` on a
 * native `self`, a test double, a template name, and a class nothing declares.
 */
final class DocumentedTypesTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function typed(): iterable
    {
        yield 'a class template bound by @param' => ['boxed'];
        yield 'foreach over Collection<int, T>' => ['iterated'];
        yield 'first() of a filtered Collection<int, T>' => ['firstOfCollection'];
        yield 'a callback on Collection<int, T>' => ['callbackOfCollection'];
        yield 'keyed destructuring of an array shape' => ['destructured'];
        yield '@return static on a native self' => ['fluentFromSubclass'];
        yield 'a test double' => ['doubled'];
        yield 'a class template bound by the constructor argument' => ['constructedBox'];
        yield 'list<TModel> bound by @extends Repository<Gateway>' => ['templateLeak'];
        yield 'an override with no docblock keeps the parent @return TModel' => ['inheritedDocblock'];
    }

    #[DataProvider('typed')]
    public function test_the_documented_type_reaches_the_call(string $method): void
    {
        $this->assertContains('App\Inference\Gateway::refund', array_column($this->callsFrom($method), 'fqn'), $method);
    }

    public function test_a_template_name_is_never_read_as_a_class(): void
    {
        $output = Cli::inLaravel(['calls-from', 'App\Inference\TypeProbes::templateLeak', '--max-tokens', '0'])->output;

        $this->assertStringNotContainsString('App\Inference\TModel', $output);
    }

    public function test_a_call_on_a_class_nothing_declares_is_unresolved_and_says_why(): void
    {
        $calls = $this->callsFrom('missingImport');

        $this->assertNotContains('App\Inference\StripeGateway::refund', array_column($calls, 'fqn'));
        $this->assertStringContainsString('a missing import?', Cli::inLaravel(['calls-from', 'App\Inference\TypeProbes::missingImport'])->output);
        $this->assertStringContainsString('App\Inference\TypeProbes::missingImport', Cli::inLaravel(['callers-of', 'App\Inference\Gateway::refund', '--all-unresolved', '--max-tokens', '0'])->output);
    }

    public function test_a_call_on_a_class_alias_lands_on_the_original(): void
    {
        $this->assertContains('App\Inference\Gateway::refund', array_column($this->callsFrom('aliased'), 'lands'));
    }

    public function test_a_class_alias_is_located_where_it_is_declared(): void
    {
        $this->assertStringContainsString('app/Inference/aliases.php:7', Cli::inLaravel(['locate', 'App\Inference\LegacyGateway'])->output);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function callsFrom(string $method): array
    {
        $run = Cli::inLaravel(['calls-from', 'App\Inference\TypeProbes::'.$method, '--json', '--max-tokens', '0']);

        $this->assertSame(0, $run->exitCode, $run->errors);

        $calls = [];

        foreach (explode("\n", trim($run->output)) as $line) {
            $record = json_decode($line, true);

            if (is_array($record) && array_key_exists('kind', $record)) {
                $calls[] = $record;
            }
        }

        return $calls;
    }
}

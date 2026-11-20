<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Graph;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Tests\Support\Cli;

/**
 * An authorization check runs a policy method the call site never names. `reaches` follows the
 * check to the policy for the class it is made against, registered or found by name.
 */
final class PolicyEdgesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function checks(): iterable
    {
        yield '$this->authorize() through $policies' => ['updateOrder', 'OrderPolicy::update', 'resolved'];
        yield 'before() runs first' => ['updateOrder', 'OrderPolicy::before', 'resolved'];
        yield 'a class name for a create check' => ['createOrder', 'OrderPolicy::before', 'resolved'];
        yield 'Gate::authorize() through Gate::policy(), kebab-case ability' => ['forceDeleteCustomer', 'CustomerPolicy::forceDelete', 'resolved'];
        yield '$user->can() on an untyped user, policy by name' => ['viewInvoice', 'InvoicePolicy::view', 'inferred'];
        yield 'an array of arguments through #[UsePolicy]' => ['deleteLine', 'LineItemRules::delete', 'resolved'];
    }

    #[DataProvider('checks')]
    public function test_a_check_reaches_the_policy_method_it_runs(string $method, string $policy, string $weakest): void
    {
        $run = Cli::inLaravel(['reaches', 'App\Http\Controllers\AuthorizedActions::'.$method, 'App\Policies\\'.$policy]);

        $this->assertSame(0, $run->exitCode, $run->output);
        $this->assertStringContainsString('authorizes  →  App\Policies\\'.$policy, $run->output);
        $this->assertStringContainsString('the weakest step is '.$weakest, $run->output);
    }

    public function test_an_ability_the_policy_does_not_declare_is_not_reached(): void
    {
        $run = Cli::inLaravel(['reaches', 'App\Http\Controllers\AuthorizedActions::createOrder', 'App\Policies\OrderPolicy::update']);

        $this->assertSame(1, $run->exitCode);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unreadable(): iterable
    {
        yield 'a subject of unknown type' => ['unknownSubject', 'on something whose type is not known'];
        yield 'an ability in a variable' => ['abilityInAVariable', 'whose ability is not a literal'];
        yield 'a model with no policy' => ['noPolicy', 'for which no policy method could be found'];
    }

    #[DataProvider('unreadable')]
    public function test_a_check_that_cannot_be_read_is_a_gap_not_a_no(string $method, string $reason): void
    {
        $run = Cli::inLaravel(['reaches', 'App\Http\Controllers\AuthorizedActions::'.$method, 'App\Policies\*']);

        $this->assertSame(1, $run->exitCode);
        $this->assertStringContainsString('1 not followed', $run->output);
        $this->assertStringContainsString($reason, $run->output);
        $this->assertStringNotContainsString('ran out of graph', $run->output);
    }
}

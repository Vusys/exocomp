<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Framework;

use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Tests\Support\Cli;

/**
 * Tier E — config keys.
 *
 * The dotted name a config key is read by appears nowhere in the file that defines it, which
 * is the whole reason grep answers this badly in one direction; in the other it finds the same
 * string in comments and fixtures alongside the call sites.
 */
final class ConfigTest extends TestCase
{
    public function test_a_nested_key_is_reported_by_its_dotted_name(): void
    {
        $run = Cli::inLaravel(['config', 'services.stripe.key']);

        $this->assertSame(0, $run->exitCode);
        $this->assertStringContainsString('services.stripe.key', $run->output);
        $this->assertStringContainsString('config/services.php', $run->output);
    }

    public function test_an_environment_value_is_reported_as_one_rather_than_as_its_default(): void
    {
        $output = Cli::inLaravel(['config', 'services.stripe.secret'])->output;

        $this->assertStringContainsString('env(STRIPE_SECRET)', $output);
        $this->assertStringContainsString('defaulting to sk_test', $output);
    }

    public function test_a_computed_value_is_declined(): void
    {
        $this->assertStringContainsString('the value is computed', Cli::inLaravel(['config', 'services.retries'])->output);
    }

    public function test_the_containing_array_is_a_key_too(): void
    {
        // `config('services.stripe')` is a real read, so the array itself has to be findable.
        $this->assertStringContainsString('array of 2', Cli::inLaravel(['config', 'services.stripe'])->output);
    }

    public function test_a_read_inside_a_namespace_is_found(): void
    {
        // An unqualified `config()` resolves to `App\Services\config` as far as the name
        // resolver is concerned. Matching on the resolved name alone finds nothing in any file
        // that has a namespace, which is all of them.
        $output = Cli::inLaravel(['config', 'services.stripe.key'])->output;

        $this->assertStringContainsString('BillingService.php', $output);
        $this->assertStringContainsString('config()', $output);
    }

    public function test_a_read_whose_key_is_computed_is_reported_rather_than_dropped(): void
    {
        $output = Cli::inLaravel(['config', 'services.timeout'])->output;

        $this->assertStringContainsString('reads whose key is not a literal', $output);
        $this->assertStringContainsString('may or may not be this one', $output);
    }

    public function test_defined_skips_the_corpus_scan(): void
    {
        $output = Cli::inLaravel(['config', 'services.stripe.key', '--defined'])->output;

        $this->assertStringContainsString('config/services.php', $output);
        $this->assertStringNotContainsString('BillingService', $output);
    }

    public function test_a_config_file_that_returns_a_variable_is_followed(): void
    {
        $output = Cli::inLaravel(['config', 'queue.connections.redis.retry_after'])->output;

        $this->assertStringContainsString('queue.connections.redis.retry_after', $output);
        $this->assertStringContainsString('90', $output);
    }

    public function test_an_unknown_key_is_not_found(): void
    {
        $run = Cli::inLaravel(['config', 'nothing.at.all']);

        $this->assertSame(1, $run->exitCode);
        $this->assertStringContainsString('no config file defines', $run->errors);
    }
}

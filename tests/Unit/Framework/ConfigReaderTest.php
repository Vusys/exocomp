<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Framework;

use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Framework\Laravel\ConfigEntry;
use Vusys\Exocomp\Framework\Laravel\ConfigReader;
use Vusys\Exocomp\Index\Index;
use Vusys\Exocomp\Project\AutoloadMap;
use Vusys\Exocomp\Project\Project;
use Vusys\Exocomp\Symbol\Confidence;
use Vusys\Exocomp\Tests\Support\Cli;

/**
 * The config reader, asserted directly.
 *
 * The dotted key a config value is read by exists nowhere in the file that defines it — it is
 * assembled from the filename and the nesting — so the assembly itself is the thing worth
 * pinning, along with what the reader refuses to claim a value for.
 */
final class ConfigReaderTest extends TestCase
{
    public function test_the_key_is_the_filename_and_the_nesting(): void
    {
        $this->assertSame('services.stripe.key', $this->definition('services.stripe.key')->key);
        $this->assertSame('config/services.php', $this->definition('services.stripe.key')->path);
    }

    public function test_a_containing_array_is_a_key_in_its_own_right(): void
    {
        $entry = $this->definition('services.stripe');

        $this->assertSame('array of 2', $entry->value);
        $this->assertSame(Confidence::Resolved, $entry->confidence);
    }

    public function test_an_environment_lookup_is_reported_as_one(): void
    {
        $entry = $this->definition('services.stripe.secret');

        $this->assertSame('env(STRIPE_SECRET)', $entry->value);
        $this->assertSame(Confidence::Inferred, $entry->confidence);
        $this->assertStringContainsString('defaulting to sk_test', (string) $entry->note);
    }

    public function test_an_environment_lookup_with_no_default_says_so(): void
    {
        $this->assertStringContainsString('no default here', (string) $this->definition('services.stripe.key')->note);
    }

    public function test_a_computed_value_is_declined(): void
    {
        $entry = $this->definition('services.retries');

        $this->assertNull($entry->value);
        $this->assertSame(Confidence::Textual, $entry->confidence);
    }

    public function test_a_literal_value_keeps_its_type_as_text(): void
    {
        $this->assertSame('30', $this->definition('services.timeout')->value);
    }

    public function test_a_file_that_returns_a_variable_is_followed(): void
    {
        $this->assertSame('90', $this->definition('queue.connections.redis.retry_after')->value);
    }

    public function test_matching_finds_a_key_its_ancestors_and_its_descendants(): void
    {
        $keys = array_map(
            static fn (ConfigEntry $entry): string => $entry->key,
            $this->reader()->matching('services.stripe'),
        );

        $this->assertContains('services.stripe', $keys);
        $this->assertContains('services.stripe.key', $keys);
        $this->assertNotContains('services.timeout', $keys);
    }

    public function test_matching_a_leaf_also_returns_the_array_above_it(): void
    {
        $keys = array_map(
            static fn (ConfigEntry $entry): string => $entry->key,
            $this->reader()->matching('services.stripe.key'),
        );

        $this->assertSame(['services.stripe', 'services.stripe.key'], $keys);
    }

    public function test_a_directory_that_is_not_there_says_so(): void
    {
        $reader = new ConfigReader;
        $reader->readDirectory('/no/such/config', static fn (string $p): string => $p);

        $this->assertSame([], $reader->definitions());
        $this->assertStringContainsString('is not there', $reader->notes()[0]);
    }

    public function test_a_read_inside_a_namespace_is_found_and_a_computed_one_is_declared(): void
    {
        $project = Project::at(Cli::laravelRoot());
        $map = AutoloadMap::forProject($project);
        $paths = Index::open($project, $map)->store->paths($project->root);

        $usages = $this->reader()->usages(
            'services.stripe.key',
            $paths,
            static fn (string $path): string => $project->path($path),
        );

        $resolved = array_values(array_filter($usages, static fn (ConfigEntry $e): bool => $e->confidence === Confidence::Resolved));
        $computed = array_values(array_filter($usages, static fn (ConfigEntry $e): bool => $e->confidence !== Confidence::Resolved));

        $this->assertCount(1, $resolved);
        $this->assertSame('config()', $resolved[0]->kind);
        $this->assertStringContainsString('BillingService', $resolved[0]->path);

        $this->assertCount(1, $computed);
        $this->assertSame('?', $computed[0]->key);
    }

    public function test_a_key_nothing_reads_has_no_usages(): void
    {
        $project = Project::at(Cli::laravelRoot());
        $map = AutoloadMap::forProject($project);
        $paths = Index::open($project, $map)->store->paths($project->root);

        $this->assertSame([], $this->reader()->usages(
            'queue.connections.redis.retry_after',
            $paths,
            static fn (string $path): string => $project->path($path),
        ));
    }

    private function definition(string $key): ConfigEntry
    {
        foreach ($this->reader()->definitions() as $entry) {
            if ($entry->key === $key) {
                return $entry;
            }
        }

        $this->fail("no definition of {$key}");
    }

    private function reader(): ConfigReader
    {
        $reader = new ConfigReader;
        $reader->readDirectory(
            Cli::laravelRoot().'/config',
            static fn (string $absolute): string => 'config/'.basename($absolute),
        );

        return $reader;
    }
}

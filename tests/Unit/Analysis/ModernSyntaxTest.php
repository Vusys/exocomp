<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Analysis;

use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Analysis\Analyser;
use Vusys\Exocomp\Analysis\Declaration;
use Vusys\Exocomp\Source\SourceFile;
use Vusys\Exocomp\Tests\Support\Cli;

/**
 * The standing regression guard on PHP-version currency.
 *
 * Building this engine rather than reusing an existing one was decided on one ground: the
 * obvious alternative's parser has no support for PHP 8.4 syntax and has not moved since 2024.
 * If these ever fail, that reasoning has been inverted and the tool has become the thing it
 * replaced — silently, because a mis-parsed property hook still produces plausible output.
 */
final class ModernSyntaxTest extends TestCase
{
    public function test_it_reads_a_get_only_property_hook(): void
    {
        $shout = $this->property('shout');

        $this->assertSame('public string $shout { get; }', $shout->signature);
        $this->assertTrue($shout->hasBody);
    }

    public function test_it_reads_a_property_with_both_hooks(): void
    {
        $this->assertSame('public string $name { get; set; }', $this->property('name')->signature);
    }

    public function test_a_hookless_property_is_not_reported_as_having_a_body(): void
    {
        $this->assertFalse($this->property('storedName')->hasBody);
    }

    public function test_it_reads_asymmetric_visibility(): void
    {
        $version = $this->property('version');

        // `public private(set)` and `public` are different APIs. Flattening them would report
        // a read-only property as writable.
        $this->assertSame(['public', 'private(set)'], $version->modifiers);
        $this->assertSame('public private(set) int $version', $version->signature);
    }

    public function test_it_reads_asymmetric_visibility_on_a_protected_property(): void
    {
        $this->assertSame(['protected', 'protected(set)'], $this->property('slug')->modifiers);
    }

    public function test_it_reads_asymmetric_visibility_on_a_promoted_property(): void
    {
        $sealed = $this->property('sealed');

        $this->assertTrue($sealed->isPromoted);
        $this->assertSame('public private(set) bool $sealed', $sealed->signature);
    }

    private function property(string $name): Declaration
    {
        $analysis = (new Analyser)->analyse(
            SourceFile::fromPath(Cli::corpusFile('src/TierA/Modern.php'), 'src/TierA/Modern.php'),
        );

        $class = $analysis->classLike('Fixtures\TierA\Modern');
        $this->assertInstanceOf(Declaration::class, $class);

        $property = $class->member($name, isProperty: true);
        $this->assertInstanceOf(Declaration::class, $property, "Modern::\${$name} not found");

        return $property;
    }
}

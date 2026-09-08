<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Analysis;

use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Analysis\Analyser;
use Vusys\Exocomp\Analysis\FileAnalysis;
use Vusys\Exocomp\Source\SourceFile;
use Vusys\Exocomp\Tests\Support\Cli;

/**
 * Anonymous classes: present, counted, and deliberately not described.
 *
 * This came out of a differential run against a 950k-line codebase, where a generous grep for
 * method declarations found 19 that the tool did not classify. Fifteen were methods of
 * anonymous classes and four were commented-out code. Nothing was genuinely missed — but a
 * file outline that showed a class's methods and silently omitted an anonymous sibling's is
 * incomplete in exactly the way the output contract forbids.
 */
final class AnonymousClassTest extends TestCase
{
    public function test_an_anonymous_class_is_recorded_with_what_it_holds(): void
    {
        $analysis = $this->analyse();

        $this->assertCount(1, $analysis->anonymousClasses);

        $anonymous = $analysis->anonymousClasses[0];
        $this->assertSame(2, $anonymous->memberCount);
        $this->assertSame('Fixtures\TierA\AbstractBase', $anonymous->extends);
    }

    public function test_its_methods_are_not_reported_as_members_of_the_named_class(): void
    {
        $class = $this->analyse()->classLike('Fixtures\TierB\Anonymous');

        $this->assertNotNull($class);

        // `greet` and `extra` belong to the anonymous class. Attributing them to `Anonymous`
        // would make `show 'Fixtures\TierB\Anonymous::greet'` promise something it cannot keep.
        $this->assertCount(1, $class->members);
        $this->assertSame('make', $class->members[0]->name);
    }

    public function test_outline_reports_it_rather_than_hiding_it(): void
    {
        $run = Cli::inCorpus(['outline', 'src/TierB/Anonymous.php']);

        $this->assertSame(0, $run->exitCode);
        $this->assertStringContainsString('── 1 not addressable ──', $run->output);
        $this->assertStringContainsString('new class extends Fixtures\TierA\AbstractBase', $run->output);
        $this->assertStringContainsString('2 members', $run->output);
    }

    public function test_a_commented_out_declaration_is_not_a_declaration(): void
    {
        $class = $this->analyse()->classLike('Fixtures\TierB\Anonymous');

        $this->assertNotNull($class);
        $this->assertNull($class->member('commentedOut', isProperty: false));
    }

    private function analyse(): FileAnalysis
    {
        return (new Analyser)->analyse(
            SourceFile::fromPath(Cli::corpusFile('src/TierB/Anonymous.php'), 'src/TierB/Anonymous.php'),
        );
    }
}

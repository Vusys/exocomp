<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Project;

use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Project\Project;
use Vusys\Exocomp\Project\SymbolLocator;
use Vusys\Exocomp\Project\SymbolNotFound;
use Vusys\Exocomp\Symbol\SymbolRef;
use Vusys\Exocomp\Tests\Support\Cli;

/**
 * Name-to-file resolution with no index at all.
 *
 * Nothing here builds anything before answering, which is the point: an index that must
 * complete before the first useful answer is what makes a tool get abandoned on first contact,
 * particularly in the disposable worktrees this is expected to run in.
 */
final class SymbolLocatorTest extends TestCase
{
    public function test_it_resolves_a_class_through_the_declared_psr4_map(): void
    {
        $located = $this->locator()->locateOwner(SymbolRef::parse('Fixtures\TierA\Baseline'));

        $this->assertSame('Fixtures\TierA\Baseline', $located->declaration->fqn);
        $this->assertSame('src/TierA/Baseline.php', $located->analysis->file->displayPath);
    }

    public function test_it_resolves_a_nested_namespace(): void
    {
        $located = $this->locator()->locateOwner(SymbolRef::parse('Fixtures\TierA\Support\Repository'));

        $this->assertSame('src/TierA/Support/Repository.php', $located->analysis->file->displayPath);
    }

    public function test_a_leading_backslash_resolves_the_same_class(): void
    {
        $located = $this->locator()->locateOwner(SymbolRef::parse('\Fixtures\TierA\Baseline'));

        $this->assertSame('Fixtures\TierA\Baseline', $located->declaration->fqn);
    }

    public function test_it_resolves_an_interface_a_trait_and_an_enum(): void
    {
        $locator = $this->locator();

        $this->assertSame('Fixtures\TierA\Contract', $locator->locateOwner(SymbolRef::parse('Fixtures\TierA\Contract'))->declaration->fqn);
        $this->assertSame('Fixtures\TierA\Loggable', $locator->locateOwner(SymbolRef::parse('Fixtures\TierA\Loggable'))->declaration->fqn);
        $this->assertSame('Fixtures\TierA\Colour', $locator->locateOwner(SymbolRef::parse('Fixtures\TierA\Colour'))->declaration->fqn);
    }

    /**
     * `Fixtures\TierB\Omega` lives in `Collisions.php`, so PSR-4 arithmetic points at a file
     * that does not exist. Only the scan finds it — and the scan confirms the declaration is
     * really there before answering, so a coincidental filename could not have produced this.
     */
    public function test_it_finds_a_class_whose_file_is_not_named_after_it(): void
    {
        $located = $this->locator()->locateOwner(SymbolRef::parse('Fixtures\TierB\Omega'));

        $this->assertSame('src/TierB/Collisions.php', $located->analysis->file->displayPath);
    }

    public function test_a_short_name_shared_across_namespaces_resolves_to_the_right_one(): void
    {
        $locator = $this->locator();

        $this->assertSame(
            'src/TierB/Deep/Alpha.php',
            $locator->locateOwner(SymbolRef::parse('Fixtures\TierB\Deep\Alpha'))->analysis->file->displayPath,
        );

        $this->assertSame(
            'src/TierB/Collisions.php',
            $locator->locateOwner(SymbolRef::parse('Fixtures\TierB\Alpha'))->analysis->file->displayPath,
        );
    }

    public function test_a_file_that_exists_but_declares_something_else_is_not_accepted(): void
    {
        // PSR-4 maps this onto `src/TierB/Casing.php`, which exists and parses — but declares
        // `Casing`, not `Missing`. A map hit is a candidate, never an answer.
        $this->expectException(SymbolNotFound::class);

        $this->locator()->locateOwner(SymbolRef::parse('Fixtures\TierB\Casing\Missing'));
    }

    public function test_an_unparseable_file_encountered_while_scanning_is_reported_not_swallowed(): void
    {
        $locator = $this->locator();

        try {
            $locator->locateOwner(SymbolRef::parse('Fixtures\Elsewhere\Unparseable'));
            $this->fail('expected the lookup to fail');
        } catch (SymbolNotFound) {
            // Expected: the only file that could have answered could not be parsed.
        }

        $this->assertNotSame([], $locator->notes());
        $this->assertStringContainsString('src/TierF/Unparseable.php', $locator->notes()[0]);
        $this->assertStringContainsString('not parsed', $locator->notes()[0]);
    }

    public function test_a_missing_class_names_where_it_looked(): void
    {
        $this->expectExceptionMessage('no declaration of "Fixtures\Nope" found');

        $this->locator()->locateOwner(SymbolRef::parse('Fixtures\Nope'));
    }

    public function test_project_discovery_walks_up_to_the_nearest_composer_json(): void
    {
        $project = Project::discover(Cli::corpusFile('src/TierA/Support'));

        $this->assertSame(realpath(Cli::corpusRoot()), $project->root);
    }

    public function test_a_path_outside_the_project_is_reported_absolutely(): void
    {
        $project = Project::at(Cli::corpusRoot());

        $this->assertSame('src/TierA/Baseline.php', $project->displayPath($project->path('src/TierA/Baseline.php')));
        $this->assertSame('/elsewhere/x.php', $project->displayPath('/elsewhere/x.php'));
    }

    private function locator(): SymbolLocator
    {
        return SymbolLocator::forProject(Project::at(Cli::corpusRoot()));
    }
}

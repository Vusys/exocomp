<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Project;

use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Project\Project;
use Vusys\Exocomp\Project\SymbolLocator;
use Vusys\Exocomp\Project\SymbolNotFound;
use Vusys\Exocomp\Symbol\SymbolRef;

/**
 * How the locator behaves once Composer has dumped a classmap.
 *
 * The order is cheapest-first, and it is measured rather than assumed: on a 950k-line Laravel
 * application, PSR-4 arithmetic places 99.9% of application classes, and the dumped classmap
 * contains none of them at all — so consulting it first, as Composer itself does, is pure cost
 * on the overwhelmingly common path.
 */
final class InstalledProjectTest extends TestCase
{
    public function test_a_psr4_placed_class_resolves(): void
    {
        $located = $this->locator()->locateOwner(SymbolRef::parse('Installed\Placed'));

        $this->assertSame('src/Placed.php', $located->analysis->file->displayPath);
    }

    public function test_a_class_only_the_classmap_knows_about_resolves(): void
    {
        $located = $this->locator()->locateOwner(SymbolRef::parse('Installed\Legacy\Mapped'));

        $this->assertSame('lib/legacy.php', $located->analysis->file->displayPath);
    }

    /**
     * The expensive fallback is not taken by default, and the fact that it was skipped is
     * reported. Silently returning "not found" when the tool declined to look would be the
     * confidently-wrong failure in its cheapest form.
     */
    public function test_a_class_sharing_a_file_is_not_found_without_the_deep_scan(): void
    {
        $locator = $this->locator();

        try {
            $locator->locateOwner(SymbolRef::parse('Installed\Hidden'));
            $this->fail('expected the lookup to decline');
        } catch (SymbolNotFound) {
            // Expected: only a content scan could place this one.
        }

        $this->assertNotSame([], $locator->notes());
        $this->assertStringContainsString('--scan', $locator->notes()[0]);
    }

    public function test_the_deep_scan_finds_it(): void
    {
        $located = $this->locator(deepScan: true)->locateOwner(SymbolRef::parse('Installed\Hidden'));

        $this->assertSame('src/Sharing.php', $located->analysis->file->displayPath);
    }

    /**
     * Without a dumped classmap there is nothing else left to try, so the scan runs unasked.
     * The main fixture corpus is exactly that case, and `Fixtures\TierB\Omega` is found there
     * with no flag.
     */
    public function test_a_project_with_no_classmap_scans_without_being_asked(): void
    {
        $project = Project::at(dirname(__DIR__, 2).'/Fixtures/corpus');
        $located = SymbolLocator::forProject($project)->locateOwner(SymbolRef::parse('Fixtures\TierB\Omega'));

        $this->assertSame('src/TierB/Collisions.php', $located->analysis->file->displayPath);
    }

    private function locator(bool $deepScan = false): SymbolLocator
    {
        return SymbolLocator::forProject(Project::at(dirname(__DIR__, 2).'/Fixtures/installed'), $deepScan);
    }
}

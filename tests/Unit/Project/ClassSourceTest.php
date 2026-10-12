<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Project;

use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Index\Index;
use Vusys\Exocomp\Project\AutoloadMap;
use Vusys\Exocomp\Project\ClassSource;
use Vusys\Exocomp\Project\Project;
use Vusys\Exocomp\Project\SymbolLocator;
use Vusys\Exocomp\Tests\Support\TemporaryProject;

/**
 * The parse cache keeps what is in use and lets go of what is not.
 *
 * It is capped, because a corpus-wide search meets more classes than memory will hold. What it
 * evicts decides what a search costs: evicting in load order threw out the framework base class
 * every model receiver walks as readily as a class met once, and one reference search re-parsed
 * it and each of its traits twenty times over.
 */
final class ClassSourceTest extends TestCase
{
    private const int CLASSES = 300;

    private TemporaryProject $project;

    protected function setUp(): void
    {
        $this->project = TemporaryProject::copyOfCorpus();

        foreach (['Hot', 'Cold', ...array_map(static fn (int $i): string => 'Passing'.$i, range(1, self::CLASSES))] as $class) {
            $this->project->write("src/Generated/{$class}.php", "<?php\n\nnamespace Fixtures\\Generated;\n\nfinal class {$class} {}\n");
        }
    }

    protected function tearDown(): void
    {
        $this->project->destroy();
    }

    public function test_a_class_in_constant_use_is_not_evicted(): void
    {
        $source = $this->source();
        $hot = $source->load('Fixtures\Generated\Hot');

        for ($i = 1; $i <= self::CLASSES; $i++) {
            $source->load('Fixtures\Generated\Passing'.$i);
            $this->assertSame($hot, $source->load('Fixtures\Generated\Hot'), "evicted after {$i} other classes");
        }
    }

    public function test_a_class_nobody_asks_for_again_is_still_let_go(): void
    {
        $source = $this->source();
        $cold = $source->load('Fixtures\Generated\Cold');

        for ($i = 1; $i <= self::CLASSES; $i++) {
            $source->load('Fixtures\Generated\Passing'.$i);
        }

        $this->assertNotSame($cold, $source->load('Fixtures\Generated\Cold'));
        $this->assertEquals($cold, $source->load('Fixtures\Generated\Cold'), 'a re-parse gives the same answer');
    }

    private function source(): ClassSource
    {
        $project = Project::at($this->project->root);
        $map = AutoloadMap::forProject($project);
        $index = Index::open($project, $map);
        $index->sync();
        $graph = $index->graph(validate: false);

        return new ClassSource($project, $graph, new SymbolLocator($project, $map));
    }
}

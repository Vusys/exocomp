<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Inference;

use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Tests\Support\Cli;
use Vusys\Exocomp\Tests\Support\Run;
use Vusys\Exocomp\Tests\Support\TemporaryProject;

/**
 * `references-to` on a member of a hierarchy: by default the references of that one
 * declaration, with a note naming the root; with `--family`, the whole set a rename has to
 * change together. Either way `resolved` is safe to rewrite as it stands.
 */
final class RenameFamilyTest extends TestCase
{
    private TemporaryProject $project;

    protected function setUp(): void
    {
        $this->project = TemporaryProject::copyOfCorpus();
        $this->project->write('src/Family/Processors.php', <<<'PHP'
            <?php

            namespace Fixtures\Family;

            abstract class Processor { abstract public function process(): void; }
            class VendorA extends Processor { public function process(): void {} }
            class VendorB extends Processor { public function process(): void {} }
            interface Fetcher { public function fetch(): void; }
            class TicketFetcher implements Fetcher { public function fetch(): void {} public function contacts(): void {} }

            class Uses
            {
                public function a(VendorA $a): void { $a->process(); }
                public function b(Processor $p): void { $p->process(); }
                public function c(VendorB $b): void { $b->process(); }
                public function d(Fetcher $f): void { $f->contacts(); }
            }
            PHP);
    }

    protected function tearDown(): void
    {
        $this->project->destroy();
    }

    public function test_by_default_an_override_is_answered_alone_and_points_at_its_root(): void
    {
        $run = $this->exocomp(['references-to', 'Fixtures\Family\VendorA::process']);

        $this->assertStringContainsString('(2 resolved, 1 inferred, 0 unresolved)', $run->output);
        $this->assertStringNotContainsString('abstract public function process', $run->output);
        $this->assertStringContainsString('overrides or implements Fixtures\Family\Processor::process', $run->output);
    }

    public function test_the_family_is_every_declaration_and_every_call_through_it(): void
    {
        $run = $this->exocomp(['references-to', 'Fixtures\Family\VendorA::process', '--family']);

        $this->assertStringContainsString('(6 resolved, 0 inferred, 0 unresolved)', $run->output);
    }

    public function test_a_call_through_an_interface_that_does_not_declare_the_method_is_not_a_likely_reference(): void
    {
        $run = $this->exocomp(['references-to', 'Fixtures\Family\TicketFetcher::contacts']);

        $this->assertStringContainsString('0 inferred, 1 unresolved', $run->output);
        $this->assertStringContainsString('which does not declare contacts()', $run->output);
    }

    /**
     * @param  list<string>  $arguments
     */
    private function exocomp(array $arguments): Run
    {
        return Cli::run([...$arguments, '--project', $this->project->root]);
    }
}

<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Tests\Support\Cli;
use Vusys\Exocomp\Tests\Support\Run;
use Vusys\Exocomp\Tests\Support\TemporaryProject;

/**
 * A member asked for on the wrong class is answered from, or pointed at, where it is declared.
 *
 * The commonest member miss is not a typo but the wrong class: the member is on a trait the
 * class uses, on the subclass that does the work, or on a sibling. The rule is the one the
 * namespace correction already follows — answer when exactly one related place could have been
 * meant, list the candidates otherwise, and exit 1 whenever nothing matched what was asked.
 */
final class MemberElsewhereTest extends TestCase
{
    private TemporaryProject $project;

    protected function setUp(): void
    {
        $this->project = TemporaryProject::copyOfCorpus();

        $this->project->write('src/Elsewhere/LinksUsers.php', <<<'PHP'
            <?php

            namespace Fixtures\Elsewhere;

            trait LinksUsers
            {
                protected function linksFor(): array
                {
                    return [];
                }
            }
            PHP);

        $this->project->write('src/Elsewhere/MapsTickets.php', <<<'PHP'
            <?php

            namespace Fixtures\Elsewhere;

            trait MapsTickets
            {
                public function mapTickets(): array
                {
                    return [];
                }
            }
            PHP);

        $this->project->write('src/Elsewhere/Fetcher.php', <<<'PHP'
            <?php

            namespace Fixtures\Elsewhere;

            abstract class Fetcher
            {
                public function fetch(): void {}
            }
            PHP);

        $this->project->write('src/Elsewhere/AlphaFetcher.php', <<<'PHP'
            <?php

            namespace Fixtures\Elsewhere;

            final class AlphaFetcher extends Fetcher
            {
                use LinksUsers;

                public function run(): array
                {
                    return $this->mapUsers($this->linksFor());
                }

                private function mapUsers(array $links): array
                {
                    return $links;
                }

                public function mapTickets(): array
                {
                    return [];
                }
            }
            PHP);

        $this->project->write('src/Elsewhere/BetaFetcher.php', <<<'PHP'
            <?php

            namespace Fixtures\Elsewhere;

            final class BetaFetcher extends Fetcher
            {
                use MapsTickets;
            }
            PHP);

        $this->project->write('src/Elsewhere/Unrelated.php', <<<'PHP'
            <?php

            namespace Fixtures\Elsewhere;

            final class Unrelated
            {
                public function orphan(): void {}
            }
            PHP);

        Cli::inProject($this->project, ['index', '--warm']);
    }

    protected function tearDown(): void
    {
        $this->project->destroy();
    }

    public function test_show_answers_a_member_from_the_trait_the_class_uses(): void
    {
        $run = $this->exocomp('show', 'Fixtures\Elsewhere\AlphaFetcher::linksFor');

        $this->assertSame(0, $run->exitCode);
        $this->assertStringContainsString('Fixtures\Elsewhere\LinksUsers::linksFor', $run->output);
        $this->assertStringContainsString('a trait it uses', $run->output);
    }

    public function test_show_answers_from_the_one_subclass_that_declares_it(): void
    {
        $run = $this->exocomp('show', 'Fixtures\Elsewhere\Fetcher::mapUsers');

        $this->assertSame(0, $run->exitCode);
        $this->assertStringContainsString('private function mapUsers', $run->output);
        $this->assertStringContainsString('answered Fixtures\Elsewhere\AlphaFetcher::mapUsers instead, from a subclass', $run->output);
    }

    /**
     * A subclass and a trait used by another subclass both declare it. Either could have been
     * meant, so both are listed — each saying how it relates — and nothing is chosen.
     */
    public function test_show_lists_rather_than_chooses_between_two_related_declarations(): void
    {
        $run = $this->exocomp('show', 'Fixtures\Elsewhere\Fetcher::mapTickets');

        $this->assertSame(1, $run->exitCode);
        $this->assertSame('', $run->output);
        $this->assertStringContainsString('Fixtures\Elsewhere\AlphaFetcher::mapTickets (a subclass)', $run->errors);
        $this->assertStringContainsString('Fixtures\Elsewhere\MapsTickets::mapTickets (a trait used by its subclass Fixtures\Elsewhere\BetaFetcher)', $run->errors);
    }

    /**
     * Sharing a name is not a relationship. An unrelated class declaring it is worth naming, and
     * never worth answering from.
     */
    public function test_an_unrelated_declaration_is_named_but_not_answered(): void
    {
        $run = $this->exocomp('show', 'Fixtures\Elsewhere\Fetcher::orphan');

        $this->assertSame(1, $run->exitCode);
        $this->assertStringContainsString('"orphan" is declared as Fixtures\Elsewhere\Unrelated::orphan', $run->errors);
        $this->assertStringNotContainsString('(', explode('declared as', $run->errors)[1]);
    }

    public function test_members_narrows_to_the_member_an_address_names(): void
    {
        $run = $this->exocomp('members', 'Fixtures\Elsewhere\AlphaFetcher::linksFor');

        $this->assertSame(0, $run->exitCode);
        $this->assertStringContainsString('(1 of ', $run->output);
        $this->assertStringContainsString('protected function linksFor', $run->output);
        $this->assertStringNotContainsString('function run', $run->output);
    }

    public function test_members_answers_from_the_one_related_class_that_declares_it(): void
    {
        $run = $this->exocomp('members', 'Fixtures\Elsewhere\Fetcher::mapUsers');

        $this->assertSame(0, $run->exitCode);
        $this->assertStringContainsString('Fixtures\Elsewhere\AlphaFetcher  (1 of ', $run->output);
        $this->assertStringContainsString('answered Fixtures\Elsewhere\AlphaFetcher::mapUsers instead', $run->output);
    }

    public function test_members_exits_one_when_nothing_related_declares_it(): void
    {
        $run = $this->exocomp('members', 'Fixtures\Elsewhere\Fetcher::mapTickets');

        $this->assertSame(1, $run->exitCode);
        $this->assertStringContainsString('"mapTickets" is declared as', $run->output);
    }

    public function test_callers_of_searches_for_the_one_related_declaration(): void
    {
        $run = $this->exocomp('callers-of', 'Fixtures\Elsewhere\Fetcher::mapUsers');

        $this->assertSame(0, $run->exitCode);
        $this->assertStringContainsString('1 caller of Fixtures\Elsewhere\AlphaFetcher::mapUsers', $run->output);
        $this->assertStringContainsString('Fixtures\Elsewhere\AlphaFetcher::run', $run->output);
        $this->assertStringContainsString('answered Fixtures\Elsewhere\AlphaFetcher::mapUsers instead', $run->output);
    }

    public function test_references_to_names_the_declarations_when_it_cannot_choose(): void
    {
        $run = $this->exocomp('references-to', 'Fixtures\Elsewhere\Fetcher::mapTickets');

        $this->assertSame(1, $run->exitCode);
        $this->assertStringContainsString('"mapTickets" is declared as', $run->output);
    }

    private function exocomp(string ...$arguments): Run
    {
        return Cli::inProject($this->project, array_values($arguments));
    }
}

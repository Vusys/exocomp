<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Project;

use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Framework\SourceRoots;
use Vusys\Exocomp\Index\SymbolRecord;
use Vusys\Exocomp\Project\AutoloadMap;
use Vusys\Exocomp\Project\DeclarationScan;
use Vusys\Exocomp\Project\Project;
use Vusys\Exocomp\Project\SymbolLocator;
use Vusys\Exocomp\Symbol\SymbolKind;
use Vusys\Exocomp\Tests\Support\Cli;

/**
 * Reading the source to answer what the index could not.
 *
 * This is the thing that stands behind every `locate` negative. It touches no index at all, on
 * purpose: its whole job is to be right in the state where the index is wrong, so sharing any
 * of the index's machinery would be sharing the failure.
 */
final class DeclarationScanTest extends TestCase
{
    public function test_it_finds_a_class_by_its_short_name(): void
    {
        $found = $this->scan()->byShortName('Repository', null);

        $this->assertContains('Fixtures\TierA\Support\Repository', $this->fqns($found));
    }

    public function test_it_finds_a_method_by_its_short_name(): void
    {
        // The case measured in the wild: `locate mapEAUserDataToTickets` returned nothing from
        // the index, and a read of the same source found sixteen declarations.
        $found = $this->scan()->byShortName('greet', null);

        $this->assertNotSame([], $found);
        $this->assertSame(['method'], array_values(array_unique(array_map(
            static fn (SymbolRecord $record): string => $record->kind->value,
            $found,
        ))));
    }

    public function test_it_finds_every_declaration_of_an_ambiguous_name(): void
    {
        $found = $this->fqns($this->scan()->byShortName('Alpha', SymbolKind::ClassType));

        $this->assertContains('Fixtures\TierB\Alpha', $found);
        $this->assertContains('Fixtures\TierB\Deep\Alpha', $found);
    }

    public function test_a_kind_restricts_what_comes_back(): void
    {
        $this->assertSame([], $this->scan()->byShortName('Repository', SymbolKind::TraitType));
    }

    public function test_a_name_nothing_declares_comes_back_empty(): void
    {
        $this->assertSame([], $this->scan()->byShortName('NothingIsCalledThisAnywhere', null));
    }

    public function test_a_name_that_is_not_an_identifier_is_refused_rather_than_searched(): void
    {
        // Nothing can be declared under it, so reading megabytes to prove that is pure cost.
        $this->assertSame([], $this->scan()->byShortName('not a name', null));
    }

    public function test_a_file_that_will_not_parse_is_reported_rather_than_skipped(): void
    {
        // "Not found" and "could not look" are different answers, and the corpus holds a file
        // that deliberately does not parse.
        $unparsed = [];
        $this->scan()->byShortName('Unparseable', null, $unparsed);

        $this->assertNotSame([], $unparsed);
        $this->assertStringContainsString('not parsed', $unparsed[0]);
    }

    public function test_a_constant_is_matched_case_sensitively(): void
    {
        // PHP folds class and method names and does not fold constants. An index that folds
        // everything answers confidently and wrongly in both directions.
        $scan = $this->laravelScan();

        $this->assertNotSame([], $scan->byShortName('TABLE', SymbolKind::Constant));
        $this->assertSame([], $scan->byShortName('table', SymbolKind::Constant));
    }

    public function test_it_reaches_inside_an_anonymous_class(): void
    {
        // A migration's members are declared nowhere a name can reach, so a scan that skipped
        // them would report nothing about the only class in the file.
        $found = $this->laravelScan()->byShortName('up', SymbolKind::Method);

        $this->assertNotSame([], $found);
        $this->assertStringContainsString('database/migrations/', $found[0]->path);
    }

    private function scan(): DeclarationScan
    {
        $project = Project::at(Cli::corpusRoot());
        $map = AutoloadMap::forProject($project);

        return new DeclarationScan($map, new SymbolLocator($project, $map));
    }

    /**
     * The Laravel fixture with its conventional roots added, which is what the console builds.
     * Without them `database/migrations` is not source as far as Composer is concerned, and the
     * scan correctly declines to read a directory nobody has called part of the project.
     */
    private function laravelScan(): DeclarationScan
    {
        $project = Project::at(Cli::laravelRoot());
        $map = SourceRoots::augment($project, AutoloadMap::forProject($project));

        return new DeclarationScan($map, new SymbolLocator($project, $map));
    }

    /**
     * @param  list<SymbolRecord>  $records
     * @return list<string>
     */
    private function fqns(array $records): array
    {
        return array_map(static fn (SymbolRecord $record): string => $record->fqn, $records);
    }
}

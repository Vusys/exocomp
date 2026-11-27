<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Rules;

use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Tests\Support\Cli;
use Vusys\Exocomp\Tests\Support\TemporaryProject;

/**
 * The ratchet.
 *
 * A guard is only worth having if it can be adopted on a codebase that does not currently pass
 * it, and only worth keeping if it does not cry wolf. So two properties matter more than
 * anything else here: an existing violation can be waived, and a waived violation stays waived
 * when the code around it moves. A baseline keyed by line number fails the second one on the
 * first unrelated edit, and a guard that fails spuriously gets switched off.
 */
final class CheckTest extends TestCase
{
    private TemporaryProject $project;

    private string $rules;

    private string $baseline;

    protected function setUp(): void
    {
        $this->project = TemporaryProject::copyOfCorpus();
        $this->rules = $this->project->path('rules.json');
        $this->baseline = $this->project->path('baseline.json');

        file_put_contents($this->rules, json_encode([
            'rules' => [[
                'id' => 'computed-metric-key',
                'message' => 'record() called with a computed key',
                'pattern' => '$X->record(...)',
                'arg-is-not-literal' => [0],
            ]],
        ]));
    }

    protected function tearDown(): void
    {
        $this->project->destroy();
    }

    public function test_violations_fail_when_there_is_no_baseline(): void
    {
        $run = Cli::inProject($this->project, ['check', $this->rules]);

        $this->assertSame(1, $run->exitCode);
        $this->assertStringContainsString('2 violations (2 new, 0 baselined)', $run->output);
        $this->assertStringContainsString('computed-metric-key', $run->output);
    }

    public function test_a_recorded_baseline_makes_the_run_clean(): void
    {
        Cli::inProject($this->project, ['check', $this->rules, '--baseline', $this->baseline, '--update-baseline']);

        $run = Cli::inProject($this->project, ['check', $this->rules, '--baseline', $this->baseline]);

        $this->assertSame(0, $run->exitCode);
        $this->assertStringContainsString('(0 new, 2 baselined)', $run->output);
    }

    public function test_a_baseline_survives_the_code_around_it_moving(): void
    {
        Cli::inProject($this->project, ['check', $this->rules, '--baseline', $this->baseline, '--update-baseline']);

        $source = (string) file_get_contents($this->project->path('src/TierB/CallSites.php'));
        $this->project->write('src/TierB/CallSites.php', str_replace(
            'final class CallSites',
            "// two inserted lines that push everything below them down\n// and here is the second\nfinal class CallSites",
            $source,
        ));

        $run = Cli::inProject($this->project, ['check', $this->rules, '--baseline', $this->baseline]);

        $this->assertSame(0, $run->exitCode, 'an unrelated edit above a waived violation must not fail the build');
    }

    public function test_a_genuinely_new_violation_fails(): void
    {
        Cli::inProject($this->project, ['check', $this->rules, '--baseline', $this->baseline, '--update-baseline']);

        $source = (string) file_get_contents($this->project->path('src/TierB/CallSites.php'));
        $this->project->write('src/TierB/CallSites.php', str_replace(
            '    protected function record(string $key, int $value): void {}',
            "    public function added(string \$k, int \$v): void\n    {\n        \$this->record(\$k, \$v);\n    }\n\n    protected function record(string \$key, int \$value): void {}",
            $source,
        ));

        $run = Cli::inProject($this->project, ['check', $this->rules, '--baseline', $this->baseline]);

        $this->assertSame(1, $run->exitCode);
        $this->assertStringContainsString('(1 new, 2 baselined)', $run->output);
    }

    /**
     * The other half of a ratchet: once a waived violation is gone, the waiver should go too,
     * or it silently re-permits the problem the next time someone reintroduces it.
     */
    public function test_a_baselined_violation_that_is_fixed_is_reported_as_stale(): void
    {
        Cli::inProject($this->project, ['check', $this->rules, '--baseline', $this->baseline, '--update-baseline']);

        $source = (string) file_get_contents($this->project->path('src/TierB/CallSites.php'));
        $this->project->write('src/TierB/CallSites.php', str_replace(
            '$this->record($key, $count);',
            "\$this->record('orders.fixed', \$count);",
            $source,
        ));

        $run = Cli::inProject($this->project, ['check', $this->rules, '--baseline', $this->baseline]);

        $this->assertSame(0, $run->exitCode);
        $this->assertStringContainsString('no longer occurs', $run->output);
    }

    public function test_a_missing_rule_file_is_an_error_that_says_where_it_looked(): void
    {
        $run = Cli::inProject($this->project, ['check']);

        $this->assertSame(2, $run->exitCode);
        $this->assertStringContainsString('exocomp-rules.json', $run->errors);
    }

    public function test_a_rule_file_with_two_rules_of_one_id_is_rejected(): void
    {
        file_put_contents($this->rules, json_encode([
            'rules' => [
                ['id' => 'duplicated', 'pattern' => '$X->record(...)'],
                ['id' => 'duplicated', 'pattern' => '$X->find(...)'],
            ],
        ]));

        $run = Cli::inProject($this->project, ['check', $this->rules]);

        // Two rules under one id would make a baseline meaningless: a violation could be waived
        // by one and reported by the other, and neither would be wrong.
        $this->assertSame(2, $run->exitCode);
        $this->assertStringContainsString('twice', $run->errors);
    }

    public function test_update_baseline_without_a_path_writes_next_to_the_rules(): void
    {
        $run = Cli::inProject($this->project, ['check', $this->rules, '--update-baseline']);

        $this->assertSame(0, $run->exitCode, $run->errors);
        $this->assertFileExists(dirname($this->rules).'/exocomp-baseline.json');
    }
}

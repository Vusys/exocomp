<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Tests\Support\Cli;
use Vusys\Exocomp\Tests\Support\Run;
use Vusys\Exocomp\Tests\Support\TemporaryProject;

/**
 * Output and options that misled: a rest pattern that matched nothing, a token cut that broke
 * JSON and dropped the unresolved block, positions as exocomp prints them, a member count that
 * hid unreadable ancestors, a baseline broken by reformatting, and buffers on standard input.
 */
final class OutputAndOptionsTest extends TestCase
{
    private TemporaryProject $project;

    protected function setUp(): void
    {
        $this->project = TemporaryProject::copyOfCorpus();
        $this->project->write('src/Raw/Queries.php', <<<'PHP'
            <?php

            namespace Fixtures\Raw;

            final class Queries
            {
                public function run($q, string $id): void
                {
                    $q->whereRaw('a = 1');
                    $q->whereRaw('a = ?', [1]);
                    $q->whereRaw("a = {$id}");
                }
            }
            PHP);
    }

    protected function tearDown(): void
    {
        $this->project->destroy();
    }

    public function test_a_named_argument_then_rest_matches_one_or_more_arguments(): void
    {
        $this->assertStringStartsWith('3 matches', $this->exocomp(['query', '$X->whereRaw($S, ...)'])->output);
        $this->assertStringStartsWith('1 match ', $this->exocomp(['query', '$X->whereRaw($S, $B, ...)'])->output);
    }

    public function test_a_receiver_predicate_lists_what_it_could_not_decide(): void
    {
        $output = $this->exocomp(['query', '$X->whereRaw(...)', '--receiver-type', 'Illuminate\Database\Eloquent\Builder'])->output;

        $this->assertStringStartsWith('0 matches', $output);
        $this->assertStringContainsString('3 not decided', $output);
    }

    public function test_a_token_cut_in_json_is_json_and_keeps_the_unresolved_summary(): void
    {
        $run = Cli::inLaravel(['callers-of', 'App\Inference\Gateway::refund', '--json', '--all-unresolved', '--max-tokens', '700']);
        $records = array_map(static fn (string $line): mixed => json_decode($line, true), explode("\n", trim($run->output)));

        $this->assertNotContains(null, $records, 'every line is JSON');
        $this->assertContains('max-tokens', array_column(array_filter($records, is_array(...)), 'reason'));
        $this->assertNotSame([], array_filter($records, static fn (mixed $record): bool => is_array($record) && isset($record['unresolved_reason'])));
    }

    public function test_show_at_accepts_a_column_and_finds_a_closure_outside_any_class(): void
    {
        $this->project->write('tests/RefundTest.php', "<?php\n\nit('refunds', function () {\n    return 1;\n});\n");

        $this->assertSame(0, $this->exocomp(['show', '--at', 'src/Raw/Queries.php:9:21'])->exitCode);

        $run = $this->exocomp(['show', '--at', 'tests/RefundTest.php:4']);

        $this->assertSame(0, $run->exitCode, $run->errors);
        $this->assertStringContainsString("it('refunds')", $run->output);
    }

    public function test_a_matching_count_says_when_ancestors_were_not_reached(): void
    {
        $output = Cli::inLaravel(['members', 'App\Inference\Shipment', '--matching', 'invoice'])->output;

        $this->assertMatchesRegularExpression('/\(\d+ of \d+ reachable members matching; \d+ ancestors? not reached\)/', $output);
    }

    public function test_a_baseline_survives_reformatting(): void
    {
        $this->project->write('exocomp-rules.json', '{"rules": [{"id": "no-raw-sql", "pattern": "$X->whereRaw($S)", "arg-is-not-literal": 0, "message": "Use bindings"}]}');
        $this->assertSame(0, $this->exocomp(['check', '--update-baseline'])->exitCode);

        $this->project->write('src/Raw/Queries.php', str_replace('$q->whereRaw("a = {$id}");', "\$q\n            ->whereRaw( \"a = {\$id}\" );", (string) file_get_contents($this->project->path('src/Raw/Queries.php'))));

        $run = $this->exocomp(['check', '--baseline', $this->project->path('exocomp-baseline.json')]);

        $this->assertSame(0, $run->exitCode, $run->output);
        $this->assertStringContainsString('0 new', $run->output);
    }

    public function test_a_rule_that_cannot_be_read_is_named(): void
    {
        $this->project->write('exocomp-rules.json', '{"rules": [{"name": "unnamed-rule", "pattern": "$X->go()"}]}');

        $run = $this->exocomp(['check']);

        $this->assertSame(2, $run->exitCode);
        $this->assertStringContainsString('rule 1 ("unnamed-rule")', $run->errors);
    }

    public function test_show_reads_an_address_out_of_a_buffer(): void
    {
        $stdin = fopen('php://memory', 'r+');
        $this->assertIsResource($stdin);
        fwrite($stdin, "<?php\nnamespace Fixtures\\Draft;\nclass Draft { public function go(): void {} }\n");
        rewind($stdin);

        $run = Cli::runWithInput($stdin, ['show', 'Fixtures\Draft\Draft::go', '--stdin', '--as', 'src/Draft/Draft.php', '--project', $this->project->root]);

        $this->assertSame(0, $run->exitCode, $run->errors);
        $this->assertStringContainsString('src/Draft/Draft.php', $run->output);
    }

    public function test_an_index_backed_command_refuses_a_buffer_rather_than_ignoring_it(): void
    {
        $run = $this->exocomp(['callers-of', 'Fixtures\Raw\Queries::run', '--stdin']);

        $this->assertSame(2, $run->exitCode);
        $this->assertStringContainsString('--stdin is not available for callers-of', $run->errors);
    }

    /**
     * @param  list<string>  $arguments
     */
    private function exocomp(array $arguments): Run
    {
        return Cli::run([...$arguments, '--project', $this->project->root]);
    }
}

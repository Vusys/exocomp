<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Tests\Support\Cli;
use Vusys\Exocomp\Tests\Support\Run;

/**
 * Addressing a declaration by the file it is written in.
 *
 * Two things forced this. The measured one: the single largest category of symbol searches
 * still done with grep after the tool was available was `grep -n 'function processBatches'
 * -A 60 <path>` — the caller already had the path, and `show` wanted a fully-qualified name,
 * so using it meant a namespace lookup before the real question.
 *
 * The absolute one: a Laravel migration is `return new class extends Migration`, an anonymous
 * class with no name at all. Without a file address there is no way to ask about it, and a
 * large application holds thousands of them carrying the only written record of a schema's
 * history.
 */
final class FileAddressTest extends TestCase
{
    public function test_a_method_is_addressable_by_its_file(): void
    {
        $run = $this->show('src/TierA/Baseline.php::greet');

        $this->assertSame(0, $run->exitCode);
        $this->assertStringContainsString('function greet', $run->output);
    }

    public function test_a_migrations_method_is_reachable_only_this_way(): void
    {
        // The class is anonymous, so there is no name to pass to any other command.
        $run = Cli::inLaravel(['show', Cli::laravelRoot().'/database/migrations/2024_02_01_000000_add_total_to_shop_orders.php::up']);

        $this->assertSame(0, $run->exitCode);
        $this->assertStringContainsString('class@anonymous::up', $run->output);
        $this->assertStringContainsString('Schema::table(self::TABLE', $run->output);
    }

    public function test_a_file_with_one_class_needs_no_member(): void
    {
        $run = $this->show('src/TierA/Support/Repository.php');

        $this->assertSame(0, $run->exitCode);
        $this->assertStringContainsString('Fixtures\TierA\Support\Repository', $run->output);
    }

    public function test_a_file_with_two_classes_refuses_to_guess(): void
    {
        // Collisions.php declares Alpha and Omega. Picking either would be a guess.
        $run = $this->show('src/TierB/Collisions.php');

        $this->assertSame(1, $run->exitCode);
        $this->assertStringContainsString('declares 2 class-likes', $run->errors);
        $this->assertStringContainsString('exocomp outline', $run->errors);
    }

    public function test_an_ambiguous_member_names_the_lines_to_choose_between(): void
    {
        // `which()` is declared by both classes in the file.
        $run = $this->show('src/TierB/Collisions.php::which');

        $this->assertSame(1, $run->exitCode);
        $this->assertStringContainsString('declares "which" 2 times, at lines', $run->errors);
        $this->assertStringContainsString('--at', $run->errors);
    }

    public function test_a_member_the_file_does_not_declare_says_so(): void
    {
        $run = $this->show('src/TierA/Support/Repository.php::noSuchMember');

        $this->assertSame(1, $run->exitCode);
        $this->assertStringContainsString('declares no member "noSuchMember"', $run->errors);
    }

    public function test_a_position_resolves_to_the_innermost_declaration(): void
    {
        // Line 35 of Baseline.php is inside a method, so the method is the answer and not the
        // class that holds it.
        $run = Cli::run(['show', '--at', Cli::corpusFile('src/TierA/Baseline.php').':36', '--project', Cli::corpusRoot()]);

        $this->assertSame(0, $run->exitCode);
        $this->assertStringContainsString('Fixtures\TierA\Baseline::', $run->output);
        $this->assertStringNotContainsString('final class Baseline', $run->output);
    }

    public function test_a_position_outside_any_declaration_is_not_found(): void
    {
        $run = Cli::run(['show', '--at', Cli::corpusFile('src/TierA/Baseline.php').':1', '--project', Cli::corpusRoot()]);

        $this->assertSame(1, $run->exitCode);
        $this->assertStringContainsString('is not inside any declaration', $run->errors);
    }

    public function test_a_position_without_a_line_is_an_error_not_a_miss(): void
    {
        $run = Cli::run(['show', '--at', 'nonsense', '--project', Cli::corpusRoot()]);

        $this->assertSame(2, $run->exitCode);
        $this->assertStringContainsString('--at wants a position as file.php:line', $run->errors);
    }

    public function test_a_path_is_resolved_against_the_project_as_well_as_the_working_directory(): void
    {
        // The spelling every answer this tool gives reports, and the spelling a diff or a stack
        // trace uses. An agent passing --project writes this one, not an absolute path.
        $run = Cli::run(['show', 'src/TierA/Baseline.php::greet', '--project', Cli::corpusRoot()]);

        $this->assertSame(0, $run->exitCode);
        $this->assertStringContainsString('function greet', $run->output);
    }

    public function test_a_position_is_resolved_against_the_project_too(): void
    {
        $run = Cli::run(['show', '--at', 'src/TierA/Baseline.php:36', '--project', Cli::corpusRoot()]);

        $this->assertSame(0, $run->exitCode);
        $this->assertStringContainsString('Fixtures\TierA\Baseline::', $run->output);
    }

    public function test_a_missing_position_reports_the_path_the_way_every_answer_does(): void
    {
        // Project-relative. An absolute path carrying a disposable worktree directory is noise.
        $run = Cli::run(['show', '--at', 'src/TierA/Baseline.php:1', '--project', Cli::corpusRoot()]);

        $this->assertStringContainsString('src/TierA/Baseline.php:1 is not inside', $run->errors);
        $this->assertStringNotContainsString(Cli::corpusRoot(), $run->errors);
    }

    public function test_a_name_that_merely_looks_like_a_path_is_still_read_as_a_name(): void
    {
        // Nothing on disk is called this, so it must fall through to symbol resolution rather
        // than being treated as a missing file.
        $run = $this->show('Fixtures\TierA\Baseline::greet');

        $this->assertSame(0, $run->exitCode);
        $this->assertStringContainsString('function greet', $run->output);
    }

    /**
     * A corpus-relative file address, made absolute so the assertions do not depend on the
     * working directory the suite happens to run from.
     */
    private function show(string $address): Run
    {
        if (str_starts_with($address, 'src/')) {
            [$path, $member] = array_pad(explode('::', $address, 2), 2, null);
            $address = Cli::corpusFile((string) $path).($member === null ? '' : '::'.$member);
        }

        return Cli::run(['show', $address, '--project', Cli::corpusRoot()]);
    }
}

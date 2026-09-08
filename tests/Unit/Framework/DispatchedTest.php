<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Framework;

use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Analysis\Analyser;
use Vusys\Exocomp\Framework\Laravel\Dispatched;
use Vusys\Exocomp\Framework\Laravel\DispatchSite;
use Vusys\Exocomp\Source\SourceFile;
use Vusys\Exocomp\Symbol\Confidence;

/**
 * The spellings a dispatch can take, in one place.
 *
 * There are six of them and they do not agree about which side is the thing being sent:
 * `dispatch(new X)` sends its argument, `X::dispatch()` sends its receiver, and
 * `Bus::dispatch(new X)` sends its argument again. Getting one of them backwards produces an
 * edge that points the wrong way, which is worse than a missing edge because it still looks
 * like an answer.
 */
final class DispatchedTest extends TestCase
{
    public function test_a_helper_sends_its_argument(): void
    {
        $sites = $this->sites('dispatch(new \App\Jobs\ProcessOrder);');

        $this->assertSame('App\Jobs\ProcessOrder', $sites[0]->class);
        $this->assertSame('dispatch()', $sites[0]->sender);
        $this->assertSame(Confidence::Resolved, $sites[0]->confidence);
    }

    public function test_every_sending_helper_is_recognised(): void
    {
        foreach (Dispatched::SENDERS as $sender) {
            $sites = $this->sites($sender.'(new \App\Jobs\ProcessOrder);');

            $this->assertCount(1, $sites, "{$sender}() was not read as a dispatch");
        }
    }

    public function test_a_namespaced_file_still_matches_the_global_helper(): void
    {
        $sites = $this->sites("namespace App\\Services;\n\ndispatch(new \\App\\Jobs\\ProcessOrder);");

        $this->assertCount(1, $sites);
    }

    public function test_a_static_call_sends_the_class_it_was_called_on(): void
    {
        $sites = $this->sites('\App\Jobs\ProcessOrder::dispatch($order);');

        $this->assertSame('App\Jobs\ProcessOrder', $sites[0]->class);
        $this->assertSame('ProcessOrder::dispatch()', $sites[0]->sender);
    }

    public function test_a_static_call_on_a_bus_sends_its_argument_instead(): void
    {
        $sites = $this->sites('\Illuminate\Support\Facades\Bus::dispatch(new \App\Jobs\ProcessOrder);');

        // Both readings are collected: the receiver is `Bus`, which is not a job, and the
        // argument is. Only the argument names something dispatchable.
        $classes = array_map(static fn (DispatchSite $site): string => $site->class, $sites);

        $this->assertContains('App\Jobs\ProcessOrder', $classes);
    }

    public function test_a_method_call_sending_a_constructed_object_is_inferred_rather_than_resolved(): void
    {
        $sites = $this->sites('$this->dispatch(new \App\Jobs\ProcessOrder);');

        $this->assertSame('App\Jobs\ProcessOrder', $sites[0]->class);
        $this->assertSame('->dispatch()', $sites[0]->sender);
        $this->assertSame(Confidence::Inferred, $sites[0]->confidence);
        $this->assertStringContainsString('receiver type is not checked', (string) $sites[0]->note);
    }

    public function test_a_dispatch_of_something_that_is_not_a_class_name_is_not_a_site(): void
    {
        $this->assertSame([], $this->sites('dispatch($job);'));
        $this->assertSame([], $this->sites('defer(fn () => null);'));
    }

    public function test_a_short_name_is_the_last_segment(): void
    {
        $this->assertSame('ProcessOrder', Dispatched::shortName('App\Jobs\ProcessOrder'));
        $this->assertSame('ProcessOrder', Dispatched::shortName('ProcessOrder'));
    }

    /**
     * @return list<DispatchSite>
     */
    private function sites(string $body): array
    {
        [, $statements] = new Analyser()->analyseWithAst(
            SourceFile::fromString("<?php\n\n".$body, '(test)'),
        );

        return Dispatched::in($statements);
    }
}

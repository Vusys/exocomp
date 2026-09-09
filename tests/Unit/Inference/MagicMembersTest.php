<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Inference;

use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Tests\Support\Cli;

/**
 * Members that exist at runtime and are not declared anywhere a parser can see.
 *
 * The specification here is a contrast: `Bag` and `AnnotatedBag` have identical bodies, and the
 * only difference between them is that one writes down what its magic does. If the two ever
 * produce the same answer the confidence model has collapsed — either the tool has started
 * guessing, or it has stopped reading what it was told — and the write side is unsafe in
 * whichever direction it went.
 */
final class MagicMembersTest extends TestCase
{
    public function test_a_call_through_bare_magic_is_admitted_rather_than_guessed(): void
    {
        $call = $this->call('Fixtures\TierD\MagicCallers::throughBag');

        // The receiver's type is known and the member is not there. Reporting `Bag::fetch` on
        // the strength of the first half is the confidently-wrong answer, and it is what the
        // tool used to do.
        $this->assertNull($call['fqn'], 'a class with __call and no annotation must name no target');
        $this->assertSame('textual', $call['confidence']);
    }

    public function test_the_same_call_against_an_annotated_twin_is_inferred(): void
    {
        $call = $this->call('Fixtures\TierD\MagicCallers::throughAnnotation');

        $this->assertSame('Fixtures\TierD\AnnotatedBag::fetch', $call['fqn']);
        $this->assertSame('inferred', $call['confidence']);
        $this->assertStringContainsString('@method', $call['reason']);
    }

    public function test_an_annotation_never_reaches_resolved(): void
    {
        // The whole confidence model in one assertion. `Bag` and `AnnotatedBag` have identical
        // bodies; if a docblock could promote one of them to resolved, the write side would
        // start rewriting on the strength of a comment.
        foreach (['throughAnnotation', 'throughStaticAnnotation', 'throughMixin', 'throughForwarder'] as $site) {
            $this->assertNotSame('resolved', $this->call('Fixtures\TierD\MagicCallers::'.$site)['confidence'], $site);
        }
    }

    public function test_a_static_annotation_is_read_too(): void
    {
        $this->assertSame('Fixtures\TierD\AnnotatedBag::make', $this->call('Fixtures\TierD\MagicCallers::throughStaticAnnotation')['fqn']);
    }

    public function test_a_mixin_lands_on_the_real_declaration(): void
    {
        $call = $this->call('Fixtures\TierD\MagicCallers::throughMixin');

        // `@mixin` converts an unknowable call into a member lookup on a named class, which is
        // what makes it worth reading: there is somewhere to jump to, and a rename of that
        // declaration has to account for this call site.
        $this->assertSame('Fixtures\TierD\Notifier::send', $call['fqn']);
        $this->assertStringContainsString('@mixin', $call['reason']);
    }

    public function test_a_single_statement_forwarder_is_followed_one_step(): void
    {
        $call = $this->call('Fixtures\TierD\MagicCallers::throughForwarder');

        $this->assertSame('Fixtures\TierD\Notifier::send', $call['fqn']);
        $this->assertStringContainsString('forwards to', $call['reason']);
    }

    public function test_a_computed_magic_target_stays_unknowable(): void
    {
        // The guard on the forwarder analysis. Following this would mean evaluating the method,
        // and a static reader that starts evaluating starts inventing.
        $this->assertNull($this->call('Fixtures\TierD\MagicCallers::throughComputed')['fqn']);
    }

    public function test_a_member_absent_from_a_class_read_to_the_end_is_not_a_call_target(): void
    {
        $run = Cli::inCorpus(['calls-from', 'Fixtures\TierB\CallSites::lookups']);

        // A final class with no parent is the only shape where an absence can be proved.
        $this->assertStringContainsString('declares no findByUuid()', $run->output);
    }

    public function test_the_reason_says_a_class_answers_through_call(): void
    {
        $run = Cli::inCorpus(['reaches', 'Fixtures\TierD\MagicCallers::throughBag', 'Fixtures\TierD\Notifier::send']);

        // "declares no fetch()" reads as a broken call. It is not one: it is a call whose far
        // end cannot be seen, and a reader should do something different about each.
        $this->assertStringContainsString('declares __call()', $run->output);
    }

    public function test_a_class_with_no_parent_is_never_told_it_has_one(): void
    {
        $run = Cli::inCorpus(['graph', 'Fixtures\TierD\MagicCallers::throughBag', '--depth', '1']);

        $this->assertStringNotContainsString('a parent or trait outside the readable source does', $run->output);
    }

    public function test_calling_a_typed_value_resolves_to_its_invoke(): void
    {
        $call = $this->call('Fixtures\TierD\MagicCallers::throughInvoke');

        // The call site writes no method name at all, which is exactly why a textual search
        // cannot find it and why the tool has to supply it.
        $this->assertSame('Fixtures\TierD\Invokable::__invoke', $call['fqn']);
        $this->assertSame('resolved', $call['confidence']);
        $this->assertSame('invoke', $call['kind']);
    }

    public function test_calling_an_untyped_value_stays_unknowable(): void
    {
        $call = $this->call('Fixtures\TierD\MagicCallers::throughUntypedInvoke');

        $this->assertNull($call['fqn']);
        $this->assertSame('dynamic function name', $call['reason']);
    }

    /**
     * The first call record a declaration makes, decoded.
     *
     * @return array{fqn: ?string, confidence: string, reason: string, kind: string}
     */
    private function call(string $symbol): array
    {
        $run = Cli::inCorpus(['calls-from', $symbol, '--json']);

        $this->assertSame(0, $run->exitCode, $run->errors);

        foreach ($run->lines() as $line) {
            $decoded = json_decode($line, true);

            if (! is_array($decoded) || ! isset($decoded['kind'])) {
                continue;
            }

            return [
                'fqn' => is_string($decoded['fqn'] ?? null) ? $decoded['fqn'] : null,
                'confidence' => is_string($decoded['confidence'] ?? null) ? $decoded['confidence'] : '',
                'reason' => is_string($decoded['reason'] ?? null) ? $decoded['reason'] : '',
                'kind' => is_string($decoded['kind']) ? $decoded['kind'] : '',
            ];
        }

        self::fail("no call records from {$symbol}");
    }
}

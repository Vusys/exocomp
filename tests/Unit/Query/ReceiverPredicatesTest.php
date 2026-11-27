<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Query;

use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Tests\Support\Cli;

/**
 * The three predicates that need inference, and the only ones that can fail for want of it.
 *
 * A receiver whose type cannot be established does not match. That is the deliberate choice:
 * letting it through would quietly widen a scoped audit into an unscoped one, and an audit that
 * silently covered more than it said is as untrustworthy as one that covered less.
 */
final class ReceiverPredicatesTest extends TestCase
{
    public function test_an_exact_receiver_type_selects_only_calls_on_that_type(): void
    {
        $run = Cli::inCorpus(['query', '$X->send($M)', '--receiver-type', 'Fixtures\TierD\Notifier']);

        $this->assertStringContainsString('X=$this->notifier', $run->output);
        $this->assertStringNotContainsString('X=$this->envelope', $run->output);
        $this->assertStringNotContainsString('X=$this->loud', $run->output);
    }

    public function test_receiver_extends_reaches_the_subclass(): void
    {
        $run = Cli::inCorpus(['query', '$X->send($M)', '--receiver-extends', 'Fixtures\TierD\Notifier']);

        // `$this->loud` is a `LoudNotifier`, which extends `Notifier`. The exact-type predicate
        // above excludes it; this one is how you ask for the family.
        $this->assertStringContainsString('X=$this->loud', $run->output);
        $this->assertStringNotContainsString('X=$this->envelope', $run->output);
    }

    public function test_receiver_implements_reaches_an_interface(): void
    {
        $run = Cli::inCorpus(['query', '$R->_M($A)', '--receiver-implements', 'Fixtures\TierA\Describable']);

        // `Colour` is an enum implementing `Describable`; nothing in the corpus calls a method
        // on one, so the honest answer is none — and the predicate must say so rather than
        // falling back to matching everything.
        $this->assertStringContainsString('0 matches', $run->output);
    }

    public function test_an_unresolvable_receiver_never_matches(): void
    {
        $run = Cli::inCorpus(['query', '$X->send($M)', '--receiver-type', 'Fixtures\TierD\Notifier']);

        // `$anything->send()` has no type behind it. Including it would make the predicate a
        // suggestion rather than a filter, so it is listed apart, as not decided.
        [$matches, $undecided] = explode('not decided', $run->output, 2) + ['', ''];

        $this->assertStringNotContainsString('X=$anything', $matches);
        $this->assertStringContainsString('X=$anything', $undecided);
    }

    public function test_without_a_receiver_predicate_every_call_is_returned(): void
    {
        $run = Cli::inCorpus(['query', '$X->send($M)']);

        $this->assertStringContainsString('X=$this->notifier', $run->output);
        $this->assertStringContainsString('X=$this->envelope', $run->output);
        $this->assertStringContainsString('X=$anything', $run->output);
    }
}

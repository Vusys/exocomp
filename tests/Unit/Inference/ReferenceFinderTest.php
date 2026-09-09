<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Inference;

use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Index\Index;
use Vusys\Exocomp\Inference\Reference;
use Vusys\Exocomp\Inference\ReferenceFinder;
use Vusys\Exocomp\Project\AutoloadMap;
use Vusys\Exocomp\Project\Project;
use Vusys\Exocomp\Symbol\Confidence;
use Vusys\Exocomp\Symbol\SymbolRef;
use Vusys\Exocomp\Tests\Support\Cli;

/**
 * The trust gate.
 *
 * A rename with 98% recall is not 98% good, it is broken — and in PHP it breaks at runtime, on
 * whichever path happens to execute, rather than at build time. So the tests that matter here
 * are not the ones proving references are found. They are the two guards either side:
 *
 *   - a same-named member on an unrelated class must never appear, or the tool is `sed`;
 *   - a receiver whose type cannot be established must appear as *unresolved*, never as absent
 *     and never as certain.
 */
final class ReferenceFinderTest extends TestCase
{
    public function test_a_call_through_a_declared_type_resolves(): void
    {
        $this->assertReference('Fixtures\TierD\NotifierCallers::direct', Confidence::Resolved);
    }

    /**
     * Polymorphism. `$this->loud` is a `LoudNotifier`, which extends `Notifier`, so the call is
     * to the same member. A rename that skipped this would break the codebase quietly.
     */
    public function test_a_call_through_a_subclass_resolves(): void
    {
        $this->assertReference('Fixtures\TierD\NotifierCallers::throughSubclass', Confidence::Resolved);
    }

    public function test_a_call_on_a_freshly_constructed_object_resolves(): void
    {
        $this->assertReference('Fixtures\TierD\NotifierCallers::fromNew', Confidence::Resolved);
    }

    public function test_both_declarations_and_the_parent_call_are_references(): void
    {
        $contexts = $this->contexts();

        $this->assertContains('Fixtures\TierD\Notifier::send', $contexts, 'the declaration itself');
        $this->assertContains('Fixtures\TierD\LoudNotifier::send', $contexts, 'the override and its parent:: call');
    }

    /**
     * The guard that separates this from a textual search. `Envelope::send()` has the same name
     * and nothing else in common; including it would make every rename wrong.
     */
    public function test_a_same_named_method_on_an_unrelated_class_is_never_reported(): void
    {
        $this->assertNotContains(
            'Fixtures\TierD\NotifierCallers::impostor',
            $this->contexts(),
            'Envelope::send has the same name and nothing else in common',
        );
    }

    /**
     * The other half of the contract. An untyped receiver is not a reference the tool can
     * stand behind, and it is not one it may drop either.
     */
    public function test_an_untyped_receiver_is_reported_as_unresolved(): void
    {
        $reference = $this->at('Fixtures\TierD\NotifierCallers::untyped');

        $this->assertInstanceOf(Reference::class, $reference);
        $this->assertSame(Confidence::Textual, $reference->confidence);
        $this->assertStringContainsString('receiver type unknown', $reference->reason);
    }

    /**
     * A docblock is a claim about the code rather than a fact of it, so however cleanly the
     * type it names relates to the target, the reference stays inferred. The write side depends
     * on this distinction: `resolved` may be rewritten mechanically, nothing else may.
     */
    public function test_a_receiver_established_from_a_docblock_is_inferred_never_resolved(): void
    {
        $reference = $this->at('Fixtures\TierD\NotifierCallers::fromDocblock');

        $this->assertInstanceOf(Reference::class, $reference);
        $this->assertSame(Confidence::Inferred, $reference->confidence);
    }

    /**
     * PHP folds case on method names, so `$x->SEND()` and `$x->send()` are the same call. This
     * is the half of the case-sensitivity rule that a strict comparison gets wrong; the other
     * half — properties and constants, which do *not* fold — is covered in the Tier B tests.
     */
    public function test_a_method_call_in_another_case_is_the_same_method(): void
    {
        $this->assertContains('Fixtures\TierD\NotifierCallers::shoutingTheMethodName', $this->contexts());
    }

    /**
     * The reason travels with the reference. A caller deciding what to do about a non-resolved
     * reference needs to know *why* it is not resolved — that is what turns a limitation into a
     * next step rather than a dead end.
     */
    public function test_an_inferred_reference_says_what_established_it(): void
    {
        $reference = $this->at('Fixtures\TierD\NotifierCallers::fromDocblock');

        $this->assertInstanceOf(Reference::class, $reference);
        $this->assertStringContainsString('@var', $reference->reason);
    }

    public function test_a_variable_method_name_matches_nothing(): void
    {
        // `$this->notifier->{$method}()` names no member, so there is nothing to report — and
        // reporting it as a reference to whichever member was being looked for would be a guess.
        $this->assertNotContains('Fixtures\TierD\NotifierCallers::dynamic', $this->contexts());
    }

    public function test_class_references_resolve_through_aliases(): void
    {
        $references = $this->find('Fixtures\TierA\Support\Repository');
        $paths = array_map(static fn (Reference $r): string => $r->path, $references);

        // `Aliases.php` writes `Store`, never `Repository`. A textual search for either name
        // finds only one end of that.
        $this->assertContains('src/TierB/Aliases.php', $paths);
    }

    public function test_a_use_statement_is_one_reference_rather_than_two(): void
    {
        $imports = array_filter(
            $this->find('Fixtures\TierA\Support\Repository'),
            static fn (Reference $r): bool => $r->path === 'src/TierB/Aliases.php',
        );

        $this->assertCount(2, $imports, 'the import and the constructor type hint, not the import twice');
    }

    /**
     * @return list<Reference>
     */
    private function find(string $symbol): array
    {
        $project = Project::at(Cli::corpusRoot());
        $index = Index::open($project, AutoloadMap::forProject($project));
        $index->sync();

        return new ReferenceFinder($project, $index)->find(SymbolRef::parse($symbol));
    }

    /**
     * The declarations the references sit in. Keying on these rather than on line numbers means
     * a fixture can grow without breaking assertions about cases it did not touch.
     *
     * @return list<string>
     */
    private function contexts(): array
    {
        return array_map(
            static fn (Reference $r): string => $r->context,
            $this->find('Fixtures\TierD\Notifier::send'),
        );
    }

    private function at(string $context): ?Reference
    {
        foreach ($this->find('Fixtures\TierD\Notifier::send') as $reference) {
            if ($reference->context === $context) {
                return $reference;
            }
        }

        return null;
    }

    private function assertReference(string $context, Confidence $confidence): void
    {
        $reference = $this->at($context);

        $this->assertInstanceOf(Reference::class, $reference, "no reference inside {$context}");
        $this->assertSame($confidence, $reference->confidence);
    }

    public function test_resolved_only_emits_nothing_the_write_side_may_not_touch(): void
    {
        $run = Cli::inCorpus(['references-to', 'Fixtures\\TierD\\Notifier::send', '--resolved-only', '--json']);

        foreach ($run->lines() as $line) {
            $decoded = json_decode($line, true);

            if (is_array($decoded) && isset($decoded['confidence'])) {
                // The flag names the set a rename may rewrite mechanically, and an inference is
                // worth reporting and never worth rewriting on.
                $text = $decoded['text'] ?? null;

                $this->assertSame('resolved', $decoded['confidence'], is_string($text) ? $text : '');
            }
        }
    }

    public function test_a_successful_search_does_not_advise_a_scan_it_did_not_need(): void
    {
        $run = Cli::inCorpus(['references-to', 'Fixtures\\TierD\\Notifier::send']);

        // The magic ladder asks about every receiver type it meets, and most of those names do
        // not exist. Surfacing that as "your result may be incomplete" cost a reader a redundant
        // re-run with --scan that produced byte-identical output.
        $this->assertStringNotContainsString('a content scan of the source roots was not run', $run->output);
    }
}

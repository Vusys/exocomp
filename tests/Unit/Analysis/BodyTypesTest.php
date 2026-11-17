<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Analysis;

use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Analysis\Analyser;
use Vusys\Exocomp\Analysis\Declaration;
use Vusys\Exocomp\Source\SourceFile;

/**
 * A property or method with no native class type takes one from the class's own code, and only
 * where the code is silent from its docblock. Both are inferences, kept apart from `typeFqn`.
 */
final class BodyTypesTest extends TestCase
{
    private const string SOURCE = <<<'PHP'
        <?php

        namespace App\Reports;

        use App\Logging\Logger;
        use App\Mail\Mailer;
        use App\Mail\Queue;

        class Report
        {
            private $logger;

            private $mailer;

            /** @var Queue */
            private $queue;

            private $mixed;

            private $defaulted = [];

            /** @var Mailer */
            private $assignedOtherwise;

            private $fromCache;

            public function __construct(Mailer $mailer, private Logger $promoted)
            {
                $this->logger = new Logger('reports');
                $this->mailer = $mailer;
                $this->mixed = new Logger('a');
                $this->defaulted = new Logger('b');
                $this->assignedOtherwise = $this->build();
                $this->fromCache = null;
            }

            public function reset(): void
            {
                $this->logger = null;
                $this->mixed = new Mailer;
                $this->fromCache ??= new Queue;
            }

            public function logger()
            {
                if (rand()) {
                    return $this->logger;
                }

                return new Logger('fallback');
            }

            public function fluent()
            {
                return $this;
            }

            public function copy()
            {
                return new self;
            }

            public function promoted()
            {
                return $this->promoted;
            }

            public function viaMethod()
            {
                return $this->typed();
            }

            public function typed(): Mailer
            {
                return $this->mailer;
            }

            /** @return Mailer */
            public function undecided()
            {
                return rand() ? $this->mailer : null;
            }

            /** @return static */
            public function documentedFluent()
            {
                return $this->build();
            }

            public function inClosure()
            {
                $make = function () {
                    return new Logger('x');
                };

                return $make();
            }

            public function generator()
            {
                yield new Logger('y');
            }

            public function bare()
            {
                return;
            }

            private function build()
            {
                return random();
            }
        }
        PHP;

    public function test_a_property_every_assignment_fills_with_one_class_is_that_class(): void
    {
        $this->assertInferred('App\Logging\Logger', 'every assignment in the class', $this->member('logger', true));
    }

    public function test_a_typed_parameter_assigned_to_a_property_types_it(): void
    {
        $this->assertInferred('App\Mail\Mailer', 'every assignment in the class', $this->member('mailer', true));
    }

    public function test_a_coalescing_assignment_counts(): void
    {
        $this->assertInferred('App\Mail\Queue', 'every assignment in the class', $this->member('fromCache', true));
    }

    public function test_a_property_with_no_assignment_falls_back_to_its_var_tag(): void
    {
        $this->assertInferred('App\Mail\Queue', '@var', $this->member('queue', true));
    }

    public function test_an_assignment_the_code_cannot_type_falls_back_to_the_var_tag(): void
    {
        $this->assertInferred('App\Mail\Mailer', '@var', $this->member('assignedOtherwise', true));
    }

    public function test_two_classes_assigned_name_nothing(): void
    {
        $this->assertNull($this->member('mixed', true)->inferredFqn);
    }

    public function test_a_non_null_default_names_nothing(): void
    {
        $this->assertNull($this->member('defaulted', true)->inferredFqn);
    }

    public function test_a_method_every_return_of_which_is_one_class_returns_it(): void
    {
        $this->assertInferred('App\Logging\Logger', 'every return in its body', $this->member('logger'));
    }

    public function test_returning_this_is_relative_to_the_receiver(): void
    {
        $this->assertInferred('static', 'every return in its body', $this->member('fluent'));
    }

    public function test_new_self_is_the_declaring_class(): void
    {
        $this->assertInferred('App\Reports\Report', 'every return in its body', $this->member('copy'));
    }

    public function test_a_promoted_property_and_a_typed_method_type_a_return(): void
    {
        $this->assertInferred('App\Logging\Logger', 'every return in its body', $this->member('promoted'));
        $this->assertInferred('App\Mail\Mailer', 'every return in its body', $this->member('viaMethod'));
    }

    public function test_a_return_the_code_cannot_type_falls_back_to_the_return_tag(): void
    {
        $this->assertInferred('App\Mail\Mailer', '@return', $this->member('undecided'));
        $this->assertInferred('static', '@return', $this->member('documentedFluent'));
    }

    public function test_a_closure_return_is_not_the_method_return(): void
    {
        $this->assertNull($this->member('inClosure')->inferredFqn);
    }

    public function test_generators_and_bare_returns_name_nothing(): void
    {
        $this->assertNull($this->member('generator')->inferredFqn);
        $this->assertNull($this->member('bare')->inferredFqn);
    }

    public function test_a_native_type_is_never_overlaid(): void
    {
        $typed = $this->member('typed');

        $this->assertSame('App\Mail\Mailer', $typed->typeFqn);
        $this->assertNull($typed->inferredFqn);
    }

    private function assertInferred(string $fqn, string $reason, Declaration $member): void
    {
        $this->assertNull($member->typeFqn);
        $this->assertSame([$fqn, $reason], [$member->inferredFqn, $member->inferredReason]);
    }

    private function member(string $name, bool $property = false): Declaration
    {
        $class = (new Analyser)->analyse(SourceFile::fromString(self::SOURCE, 'Report.php'))->classLike('App\Reports\Report');
        $this->assertInstanceOf(Declaration::class, $class);

        $member = $class->member($name, isProperty: $property);
        $this->assertInstanceOf(Declaration::class, $member);

        return $member;
    }
}

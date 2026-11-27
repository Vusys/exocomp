<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Framework\Laravel;

use PhpParser\Node;
use PhpParser\NodeFinder;
use Vusys\Exocomp\Analysis\Analyser;
use Vusys\Exocomp\Analysis\LiteralValue;
use Vusys\Exocomp\Symbol\Confidence;

/**
 * The classes a piece of code sends.
 *
 * Split out from the reader that answers "where is this job sent" because the graph needs the
 * same question asked the other way round — "what does this method send" — and two lists of
 * the spellings a dispatch can take would drift apart. When they did, the graph would be short
 * an edge the `dispatches` command reports, or the reverse, and neither answer could be
 * checked against the other.
 */
final class Dispatched
{
    /**
     * Free functions that send something. Each takes the thing itself, so what is dispatched is
     * the argument rather than the receiver.
     *
     * @var list<string>
     */
    public const array SENDERS = ['dispatch', 'dispatch_sync', 'event', 'defer'];

    /**
     * Static spellings of the same thing: `ProcessOrder::dispatch(…)` sends the class it is
     * called on, so there the receiver is the subject and the arguments are its constructor's.
     *
     * @var list<string>
     */
    public const array STATIC_SENDERS = ['dispatch', 'dispatchsync', 'dispatchif', 'dispatchunless', 'dispatchafterresponse', 'dispatchnow'];

    /**
     * Spellings that send their argument rather than their receiver: `Bus::dispatch(new X)`,
     * and the same shape on a `$this->dispatch(…)` from the framework's own trait.
     *
     * @var list<string>
     */
    public const array ARGUMENT_SENDERS = ['dispatch', 'dispatchsync', 'dispatchnow'];

    /**
     * @param  array<int, Node>  $statements
     * @return list<DispatchSite>
     */
    public static function in(array $statements): array
    {
        // A variable assigned `new X` once in a function is an X wherever that function sends it:
        // `$job = new ShipOrder(1); dispatch($job);`.
        self::$assigned = [];

        // A parameter typed with a class is that class too: `handle(ShipOrder $job) { dispatch($job); }`.
        foreach (new NodeFinder()->findInstanceOf($statements, Node\Param::class) as $param) {
            if ($param->var instanceof Node\Expr\Variable && is_string($param->var->name) && $param->type instanceof Node\Name) {
                $class = Analyser::resolvedName($param->type);
                $previous = self::$assigned[$param->var->name] ?? null;
                self::$assigned[$param->var->name] = $previous === null || $previous === $class ? $class : '';
            }
        }

        foreach (new NodeFinder()->findInstanceOf($statements, Node\Expr\Assign::class) as $assign) {
            if ($assign->var instanceof Node\Expr\Variable && is_string($assign->var->name)
                && $assign->expr instanceof Node\Expr\New_ && $assign->expr->class instanceof Node\Name) {
                $class = Analyser::resolvedName($assign->expr->class);
                $previous = self::$assigned[$assign->var->name] ?? null;
                self::$assigned[$assign->var->name] = $previous === null || $previous === $class ? $class : '';
            }
        }

        $sites = [];

        foreach ([...self::throughFunctions($statements), ...self::throughStaticCalls($statements), ...self::throughMethodCalls($statements)] as $site) {
            $sites[$site->position.':'.$site->class] ??= $site;
        }

        return array_values($sites);
    }

    /** @var array<string, string> variable to the one class assigned to it; '' when not one */
    private static array $assigned = [];

    /** Static calls that queue every job in their array argument: `Bus::chain([...])`. */
    private const array SENDS_A_LIST = ['chain', 'batch', 'withchain'];

    /** `Queue::push($job)` and its relatives, with where the job is among the arguments. */
    private const array QUEUE_PUSHES = ['push' => 0, 'pushon' => 1, 'later' => 1, 'lateron' => 2, 'pushraw' => -1];

    /**
     * @param  array<int, Node>  $statements
     * @return list<DispatchSite>
     */
    private static function throughFunctions(array $statements): array
    {
        $sites = [];

        foreach (new NodeFinder()->findInstanceOf($statements, Node\Expr\FuncCall::class) as $call) {
            if (! $call->name instanceof Node\Name) {
                continue;
            }

            foreach (self::SENDERS as $sender) {
                if (! Analyser::isGlobalFunction($call->name, $sender)) {
                    continue;
                }

                $argument = Analyser::arguments($call)[0]->value ?? null;
                $sent = self::sent($argument);

                if ($sent !== null) {
                    $sites[] = new DispatchSite($sent, $sender.'()', $call->getStartLine(), $call->getStartFilePos());
                }
            }
        }

        return $sites;
    }

    /**
     * @param  array<int, Node>  $statements
     * @return list<DispatchSite>
     */
    private static function throughStaticCalls(array $statements): array
    {
        $sites = [];

        foreach (new NodeFinder()->findInstanceOf($statements, Node\Expr\StaticCall::class) as $call) {
            if (! $call->class instanceof Node\Name || ! $call->name instanceof Node\Identifier) {
                continue;
            }

            $class = Analyser::resolvedName($call->class);
            $spelling = self::shortName($class).'::'.$call->name->toString().'()';
            $method = strtolower($call->name->toString());

            // `ProcessOrder::dispatch(…)` sends the class it was called on, and so does
            // `ProcessOrder::withChain([...])->dispatch()`.
            if (in_array($method, self::STATIC_SENDERS, true) || $method === 'withchain') {
                $sites[] = new DispatchSite($class, $spelling, $call->getStartLine(), $call->getStartFilePos());
            }

            // `Bus::chain([new A, new B])`, `Bus::batch([...])`, `X::withChain([...])`: each job listed.
            if (in_array($method, self::SENDS_A_LIST, true) && ($list = Analyser::arguments($call)[0]->value ?? null) instanceof Node\Expr\Array_) {
                foreach ($list->items as $item) {
                    $sent = self::sent($item->value);

                    if ($sent !== null) {
                        $sites[] = new DispatchSite($sent, $spelling, $call->getStartLine(), $call->getStartFilePos());
                    }
                }
            }

            // `Queue::push(new ShipOrder)`, `Queue::later($delay, new ShipOrder)`.
            if (isset(self::QUEUE_PUSHES[$method]) && self::QUEUE_PUSHES[$method] >= 0 && strcasecmp(self::shortName($class), 'Queue') === 0) {
                $argument = Analyser::arguments($call)[self::QUEUE_PUSHES[$method]]->value ?? null;
                $sent = self::sent($argument);

                if ($sent !== null) {
                    $sites[] = new DispatchSite($sent, $spelling, $call->getStartLine(), $call->getStartFilePos());
                }
            }

            // `Bus::dispatch(new ProcessOrder)` and `Event::dispatch(new OrderPlaced)` send the
            // argument instead — and the same call can be both spellings only by accident, so
            // the argument form is checked separately rather than as an else.
            if (! in_array($method, self::ARGUMENT_SENDERS, true)) {
                continue;
            }

            $argument = Analyser::arguments($call)[0]->value ?? null;
            $sent = self::sent($argument);

            if ($sent !== null) {
                $sites[] = new DispatchSite($sent, $spelling, $call->getStartLine(), $call->getStartFilePos());
            }
        }

        return $sites;
    }

    /**
     * `$this->dispatch(new ProcessOrder)` — the framework's own trait, and every wrapper class
     * that has grown one of these.
     *
     * The receiver's type is not checked, so this is inferred rather than resolved: a method
     * named `dispatch` taking a freshly constructed object is a dispatch in almost every case
     * and in a minority of cases is something else entirely.
     *
     * @param  array<int, Node>  $statements
     * @return list<DispatchSite>
     */
    private static function throughMethodCalls(array $statements): array
    {
        $sites = [];

        foreach (new NodeFinder()->findInstanceOf($statements, Node\Expr\MethodCall::class) as $call) {
            if (! $call->name instanceof Node\Identifier || ! in_array(strtolower($call->name->toString()), self::ARGUMENT_SENDERS, true)) {
                continue;
            }

            $argument = Analyser::arguments($call)[0]->value ?? null;
            $sent = self::sent($argument);

            // Its receiver is not checked, so an argument of unknown type is not even a send.
            if ($sent !== null && $sent !== '') {
                $sites[] = new DispatchSite(
                    $sent,
                    '->'.$call->name->toString().'()',
                    $call->getStartLine(),
                    $call->getStartFilePos(),
                    Confidence::Inferred,
                    'sent through a method call, whose receiver type is not checked',
                );
            }
        }

        return $sites;
    }

    /**
     * The class an argument sends; '' where it is an object of a type not decided here (a
     * variable, a property, a call), so the send is reported rather than dropped; null where it
     * sends no class at all (a string event name, a closure).
     */
    private static function sent(?Node\Expr $argument): ?string
    {
        if (! $argument instanceof Node\Expr) {
            return null;
        }

        $class = self::constructedClass($argument);

        if ($class !== null) {
            return $class;
        }

        return $argument instanceof Node\Scalar || $argument instanceof Node\Expr\ConstFetch || $argument instanceof Node\Expr\ClassConstFetch
            || $argument instanceof Node\Expr\Array_ || $argument instanceof Node\Expr\Closure || $argument instanceof Node\Expr\ArrowFunction
            || $argument instanceof Node\Expr\BinaryOp\Concat
            ? null
            : '';
    }

    private static function constructedClass(Node\Expr $expression): ?string
    {
        if ($expression instanceof Node\Expr\New_ && $expression->class instanceof Node\Name) {
            return Analyser::resolvedName($expression->class);
        }

        if ($expression instanceof Node\Expr\Variable && is_string($expression->name) && (self::$assigned[$expression->name] ?? '') !== '') {
            return self::$assigned[$expression->name];
        }

        return LiteralValue::className($expression);
    }

    public static function shortName(string $fqn): string
    {
        $position = strrpos($fqn, '\\');

        return $position === false ? $fqn : substr($fqn, $position + 1);
    }
}

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
        return [
            ...self::throughFunctions($statements),
            ...self::throughStaticCalls($statements),
            ...self::throughMethodCalls($statements),
        ];
    }

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

                $argument = $call->getArgs()[0]->value ?? null;
                $sent = $argument instanceof Node\Expr ? self::constructedClass($argument) : null;

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

            // `ProcessOrder::dispatch(…)` sends the class it was called on.
            if (in_array($method, self::STATIC_SENDERS, true)) {
                $sites[] = new DispatchSite($class, $spelling, $call->getStartLine(), $call->getStartFilePos());
            }

            // `Bus::dispatch(new ProcessOrder)` and `Event::dispatch(new OrderPlaced)` send the
            // argument instead — and the same call can be both spellings only by accident, so
            // the argument form is checked separately rather than as an else.
            if (! in_array($method, self::ARGUMENT_SENDERS, true)) {
                continue;
            }

            $argument = $call->getArgs()[0]->value ?? null;
            $sent = $argument instanceof Node\Expr ? self::constructedClass($argument) : null;

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

            $argument = $call->getArgs()[0]->value ?? null;
            $sent = $argument instanceof Node\Expr ? self::constructedClass($argument) : null;

            if ($sent !== null) {
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

    private static function constructedClass(Node\Expr $expression): ?string
    {
        if ($expression instanceof Node\Expr\New_ && $expression->class instanceof Node\Name) {
            return Analyser::resolvedName($expression->class);
        }

        return LiteralValue::className($expression);
    }

    public static function shortName(string $fqn): string
    {
        $position = strrpos($fqn, '\\');

        return $position === false ? $fqn : substr($fqn, $position + 1);
    }
}

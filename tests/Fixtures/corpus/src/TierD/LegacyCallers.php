<?php

declare(strict_types=1);

namespace Fixtures\TierD;

/**
 * Code written before native types: nothing declares a class, but the code and the docblocks
 * say which one.
 */
final class LegacyCallers
{
    private $notifier;

    /** @var Notifier */
    private $documented;

    public function __construct()
    {
        $this->notifier = new Notifier;
    }

    /** The property's every assignment in the class is a Notifier. */
    public function viaAssignedProperty(): bool
    {
        return $this->notifier->send('hello');
    }

    /** Only the docblock says. */
    public function viaVarTag(): bool
    {
        return $this->documented->send('hello');
    }

    /** The method's every return is a Notifier. */
    public function viaUntypedReturn(): bool
    {
        return $this->notifier()->send('hello');
    }

    public function notifier()
    {
        return $this->notifier;
    }
}

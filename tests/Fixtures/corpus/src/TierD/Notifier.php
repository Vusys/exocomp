<?php

declare(strict_types=1);

namespace Fixtures\TierD;

/**
 * The reference-resolution guards, in one hierarchy plus one impostor.
 *
 * Every `send()` below matches a search for the name. Only some of them are the same member,
 * and telling them apart is the whole difference between a rename that works and one that
 * breaks a codebase at runtime.
 */
class Notifier
{
    public function send(string $message): bool
    {
        return true;
    }
}

/**
 * Overrides `send()`. A rename that missed this would break polymorphism silently.
 */
final class LoudNotifier extends Notifier
{
    public function send(string $message): bool
    {
        return parent::send(strtoupper($message));
    }
}

/**
 * A completely unrelated class that happens to declare a method of the same name. Nothing here
 * may ever appear in references to `Notifier::send` — this is the false-positive guard, and it
 * is what separates the tool from `sed`.
 */
final class Envelope
{
    public function send(string $message): bool
    {
        return false;
    }
}

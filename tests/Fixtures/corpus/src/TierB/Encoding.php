<?php

declare(strict_types=1);

namespace Fixtures\TierB;

/**
 * Text that is not Latin-1, and longer than the value budget a dense signature allows.
 *
 * Capping such a value by bytes rather than by characters cuts a multi-byte character in half
 * and leaves a dangling continuation byte, which stops the whole record being valid UTF-8 —
 * and therefore stops `--json` being able to encode it at all.
 */
final class Encoding
{
    public const string GREETING = 'こんにちは世界、これは長い文字列です。切り詰められるべきです。';

    protected string $persian = 'در یک روز آفتابی، همه چیز روشن و زیبا به نظر می رسید و ما خوشحال بودیم';

    public string $emoji = '🙂🙃🙂🙃🙂🙃🙂🙃🙂🙃🙂🙃🙂🙃🙂🙃🙂🙃🙂🙃🙂🙃🙂🙃🙂🙃';
}

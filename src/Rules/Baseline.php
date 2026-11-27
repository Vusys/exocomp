<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Rules;

use InvalidArgumentException;
use RuntimeException;

/**
 * The set of violations already known about, so that only new ones fail a build.
 *
 * This is what makes a sweep adoptable on a codebase that does not currently pass it. Without a
 * baseline the choice is to fix several hundred existing violations before the guard can be
 * turned on at all, which in practice means it never is; with one, the rule starts guarding
 * immediately and the existing set can only shrink.
 *
 * Counts are kept per fingerprint rather than a bare set, so a second identical violation
 * appearing in the same method is still reported as new.
 */
final readonly class Baseline
{
    /**
     * @param  array<string, int>  $counts  fingerprint => how many were waived
     */
    private function __construct(private array $counts) {}

    public static function empty(): self
    {
        return new self([]);
    }

    public static function fromFile(string $path): self
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("could not read the baseline \"{$path}\"");
        }

        $decoded = json_decode($contents, true);

        if (! is_array($decoded) || ! is_array($decoded['waived'] ?? null)) {
            throw new InvalidArgumentException("\"{$path}\" is not an exocomp baseline");
        }

        $counts = [];

        foreach ($decoded['waived'] as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $fingerprint = self::fingerprintOf($entry);

            if ($fingerprint !== null) {
                $counts[$fingerprint] = (is_int($entry['count'] ?? null) ? $entry['count'] : 1);
            }
        }

        return new self($counts);
    }

    /**
     * Splits violations into the ones already waived and the ones that are new.
     *
     * @param  list<Violation>  $violations
     * @return array{new: list<Violation>, waived: list<Violation>}
     */
    public function partition(array $violations): array
    {
        $remaining = $this->counts;
        $new = [];
        $waived = [];

        foreach ($violations as $violation) {
            $fingerprint = $this->claim($remaining, $violation);

            if ($fingerprint !== null) {
                $remaining[$fingerprint]--;
                $waived[] = $violation;

                continue;
            }

            $new[] = $violation;
        }

        return ['new' => $new, 'waived' => $waived];
    }

    /**
     * Violations recorded in the baseline that no longer occur — the ratchet's other half. They
     * are not a failure, but leaving them in place would quietly re-permit a problem that has
     * already been fixed.
     *
     * @param  list<Violation>  $violations
     */
    public function stale(array $violations): int
    {
        $remaining = $this->counts;

        foreach ($violations as $violation) {
            $fingerprint = $this->claim($remaining, $violation);

            if ($fingerprint !== null) {
                $remaining[$fingerprint]--;
            }
        }

        return array_sum($remaining);
    }

    /**
     * The baseline entry a violation is waived by: its shape, or, for a baseline written before
     * shapes were kept, the code as it was written then.
     *
     * @param  array<string, int>  $remaining
     */
    private function claim(array $remaining, Violation $violation): ?string
    {
        foreach ([$violation->fingerprint(), $violation->legacyFingerprint()] as $fingerprint) {
            if (($remaining[$fingerprint] ?? 0) > 0) {
                return $fingerprint;
            }
        }

        return null;
    }

    /**
     * @param  list<Violation>  $violations
     */
    public static function write(string $path, array $violations): void
    {
        $counts = [];
        $details = [];

        foreach ($violations as $violation) {
            $fingerprint = $violation->fingerprint();
            $counts[$fingerprint] = ($counts[$fingerprint] ?? 0) + 1;
            $details[$fingerprint] = $violation;
        }

        $waived = [];

        foreach ($details as $fingerprint => $violation) {
            $waived[] = [
                'rule' => $violation->ruleId,
                'file' => $violation->path,
                'context' => $violation->context,
                'code' => $violation->snippet,
                'shape' => $violation->shape ?? $violation->snippet,
                'count' => $counts[$fingerprint],
            ];
        }

        // Sorted so that regenerating a baseline produces a reviewable diff rather than a
        // reshuffle, and no line number is stored at all — see {@see Violation::fingerprint()}.
        usort($waived, static fn (array $a, array $b): int => [$a['rule'], $a['file'], $a['context'], $a['code']]
            <=> [$b['rule'], $b['file'], $b['context'], $b['code']]);

        $encoded = json_encode(['waived' => $waived], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($encoded === false || @file_put_contents($path, $encoded."\n") === false) {
            throw new RuntimeException("could not write the baseline \"{$path}\"");
        }
    }

    /**
     * @param  array<array-key, mixed>  $entry
     */
    private static function fingerprintOf(array $entry): ?string
    {
        foreach (['rule', 'file', 'context', 'code'] as $key) {
            if (! is_string($entry[$key] ?? null)) {
                return null;
            }
        }

        $code = is_string($entry['shape'] ?? null) ? $entry['shape'] : $entry['code'];

        return $entry['rule']."\0".$entry['file']."\0".$entry['context']."\0".$code;
    }
}

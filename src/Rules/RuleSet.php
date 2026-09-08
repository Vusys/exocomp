<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Rules;

use InvalidArgumentException;
use RuntimeException;

/**
 * A file of saved rules.
 *
 * JSON rather than YAML on purpose: it needs no extension, no dependency and no ambiguity about
 * indentation, and a rule file that cannot be read on a bare PHP installation is a rule file
 * that will not run in CI.
 */
final readonly class RuleSet
{
    /**
     * @param  list<Rule>  $rules
     */
    public function __construct(public array $rules) {}

    public static function fromFile(string $path): self
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("could not read the rule file \"{$path}\"");
        }

        $decoded = json_decode($contents, true);

        if (! is_array($decoded)) {
            throw new InvalidArgumentException("\"{$path}\" is not a JSON object");
        }

        $definitions = $decoded['rules'] ?? null;

        if (! is_array($definitions)) {
            throw new InvalidArgumentException("\"{$path}\" has no \"rules\" array");
        }

        $rules = [];
        $seen = [];

        foreach ($definitions as $definition) {
            if (! is_array($definition)) {
                continue;
            }

            /** @var array<string, mixed> $definition */
            $rule = Rule::fromArray($definition);

            // Two rules under one id would make a baseline meaningless: a violation could be
            // waived by one and reported by the other, and neither would be wrong.
            if (isset($seen[$rule->id])) {
                throw new InvalidArgumentException("\"{$path}\" defines the rule \"{$rule->id}\" twice");
            }

            $seen[$rule->id] = true;
            $rules[] = $rule;
        }

        if ($rules === []) {
            throw new InvalidArgumentException("\"{$path}\" defines no rules");
        }

        return new self($rules);
    }
}

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

        foreach (array_values($definitions) as $position => $definition) {
            if (! is_array($definition)) {
                continue;
            }

            /** @var array<string, mixed> $definition */
            try {
                $rule = Rule::fromArray($definition);
            } catch (InvalidArgumentException $problem) {
                // Which rule, or a file of thirty leaves the reader to find it by elimination.
                $name = is_string($definition['id'] ?? null) ? $definition['id'] : (is_string($definition['name'] ?? null) ? $definition['name'] : null);

                throw new InvalidArgumentException(sprintf('"%s", rule %d%s: %s', $path, $position + 1, $name === null ? '' : ' ("'.$name.'")', $problem->getMessage()), 0, $problem);
            }

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

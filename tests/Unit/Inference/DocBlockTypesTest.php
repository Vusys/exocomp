<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Inference;

use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Inference\DocBlockTypes;

/**
 * What an array type says of its elements: one class at any key, or one per key of a shape.
 */
final class DocBlockTypesTest extends TestCase
{
    /** A generic class's last parameter is what it holds, as an array's value type is. */
    public function test_array_forms_name_the_element_at_any_key(): void
    {
        foreach (['Page[]', 'list<Page>', 'array<string, Page>', 'iterable<Page>', '?Page[]', 'non-empty-list<Page>', 'Collection<int, Page>'] as $type) {
            $this->assertSame(['*' => 'Page'], $this->returned($type), $type);
        }
    }

    public function test_a_shape_names_one_class_per_key_and_skips_the_rest(): void
    {
        $this->assertSame(['book' => 'Book', '2' => 'Page'], $this->returned('array{book: Book, label: string, 2: Page}'));
        $this->assertSame(['0' => 'Book', '1' => 'Page'], $this->returned('array{Book, Page}'));
    }

    public function test_an_element_that_is_not_one_class_names_nothing(): void
    {
        foreach (['string[]', 'array<int, Book|Page>', 'array<string, Page[]>', 'Book|Page[]', 'Page'] as $type) {
            $this->assertSame([], $this->returned($type), $type);
        }
    }

    public function test_params_and_named_vars_are_keyed_by_variable(): void
    {
        $found = (new DocBlockTypes)->elements("/**\n * @param list<Page> \$pages\n * @param int \$n\n * @var Book[] \$books\n */");

        $this->assertSame(['pages' => ['*' => 'Page']], $found['params']);
        $this->assertSame(['books' => ['*' => 'Book']], $found['vars']);
        $this->assertSame(['*' => 'Book'], $found['var']);
    }

    /**
     * @return array<string, string>
     */
    private function returned(string $type): array
    {
        return (new DocBlockTypes)->elements("/** @return {$type} */")['return'];
    }
}

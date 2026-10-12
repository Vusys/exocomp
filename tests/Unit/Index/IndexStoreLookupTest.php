<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Index;

use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Index\FileFacts;
use Vusys\Exocomp\Index\IndexStore;

/**
 * A lookup costs what its answer costs, not what the corpus costs.
 *
 * Every symbol lookup used to be driven from the root's list of files and joined out to the
 * symbol, so each one walked every file in the project. Memoisation hid it on small questions;
 * a reference search for a common name asks thousands of distinct ones, and on a 7,000-file
 * application spent three minutes doing nothing else. The failure is invisible on the fixture
 * corpus, so this builds a corpus big enough to show it and asks the questions a search asks.
 */
final class IndexStoreLookupTest extends TestCase
{
    private const int FILES = 5000;

    private const string ROOT = '/project';

    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir().'/exocomp-lookup-'.bin2hex(random_bytes(6)).'.sqlite';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->path.'*') ?: [] as $file) {
            @unlink($file);
        }
    }

    public function test_lookups_do_not_walk_every_file_in_the_root(): void
    {
        $store = $this->corpus();
        $started = microtime(true);

        for ($i = 0; $i < 600; $i++) {
            $class = 'App\Generated\Class'.($i * 17 % self::FILES);

            $this->assertCount(1, $store->byFqn(self::ROOT, $class));
            $this->assertCount(2, $store->membersOf(self::ROOT, $class));
            $this->assertCount(1, $store->edgesTo(self::ROOT, 'extends', 'App\Generated\Base'.$i));
            $this->assertCount(1, $store->pathsMentioning(self::ROOT, ['handle'.$i]));
            $this->assertSame([], $store->byFqn(self::ROOT, 'Vendor\Absent'.$i));
        }

        // Measured at ~0.2s with the lookups driven from the symbol and ~7s driven from the
        // root; the bound sits far enough from both that a slow machine cannot confuse them.
        $this->assertLessThan(2.0, microtime(true) - $started);
    }

    /**
     * A case-sensitive member is stored without a folded name, so finding it by its exact
     * spelling is the only way it can be found at all.
     */
    public function test_a_case_sensitive_member_is_found_by_its_exact_name(): void
    {
        $store = $this->corpus(files: 1);

        $this->assertCount(1, $store->byFqn(self::ROOT, 'App\Generated\Class0::$value'));
        $this->assertSame([], $store->byFqn(self::ROOT, 'App\Generated\Class0::$VALUE'));
    }

    private function corpus(int $files = self::FILES): IndexStore
    {
        $store = new IndexStore($this->path);
        $store->beginBatch();

        for ($i = 0; $i < $files; $i++) {
            $class = 'App\Generated\Class'.$i;
            $hash = 'hash'.$i;

            $store->putBlob($hash, new FileFacts(
                symbols: [
                    $this->symbol($class, 'Class'.$i, 'class', null),
                    $this->symbol($class.'::handle'.$i, 'handle'.$i, 'method', $class),
                    $this->symbol($class.'::$value', 'value', 'property', $class),
                ],
                edges: [['from' => $class, 'kind' => 'extends', 'to' => 'App\Generated\Base'.$i]],
                namespaces: ['App\Generated'],
                referencedNames: ['handle'.$i],
            ));
            $store->putFile(self::ROOT, 'app/Generated/Class'.$i.'.php', $hash, 0, 0);
        }

        $store->commitBatch();

        return $store;
    }

    /**
     * @return array<string, string|int|bool|null>
     */
    private function symbol(string $fqn, string $name, string $kind, ?string $owner): array
    {
        return [
            'fqn' => $fqn,
            'name' => $name,
            'kind' => $kind,
            'owner' => $owner,
            'line' => 1,
            'end_line' => 1,
            'col' => 1,
            'span_line' => 1,
            'signature' => '',
            'full_signature' => '',
            'modifiers' => '',
            'promoted' => false,
            'has_body' => true,
            'type_fqn' => null,
        ];
    }
}

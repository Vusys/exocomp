<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Index;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Index\IndexStore;

/**
 * Two runs against the shared cache wait for each other rather than failing.
 *
 * A batch reads before it writes. Opened with a deferred `BEGIN`, it held only a read snapshot
 * until its first write, and if another process committed in between, SQLite refused the upgrade
 * at once with "database is locked" — the busy timeout does not apply to that case. Holding the
 * write lock from the start of the batch is what makes the other process wait instead.
 */
final class IndexStoreConcurrencyTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir().'/exocomp-concurrency-'.bin2hex(random_bytes(6)).'.sqlite';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->path.'*') ?: [] as $file) {
            @unlink($file);
        }
    }

    public function test_a_batch_holds_the_write_lock_from_its_start(): void
    {
        $store = new IndexStore($this->path);
        $store->beginBatch();
        $store->hasBlob('abc');

        $other = new PDO('sqlite:'.$this->path, options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 0,
        ]);

        try {
            $other->exec("INSERT INTO files (root, path, hash, mtime, size) VALUES ('/other', 'a.php', 'x', 0, 0)");
            $this->fail('another connection wrote while a batch was open');
        } catch (PDOException $e) {
            $this->assertStringContainsString('locked', $e->getMessage());
        }

        $store->putFile('/project', 'b.php', 'y', 0, 0);
        $store->commitBatch();

        $other->exec("INSERT INTO files (root, path, hash, mtime, size) VALUES ('/other', 'a.php', 'x', 0, 0)");
        $this->assertCount(1, $store->filesIn('/project'));
        $this->assertCount(1, $store->filesIn('/other'));
    }
}

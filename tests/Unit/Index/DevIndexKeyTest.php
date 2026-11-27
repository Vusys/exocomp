<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Tests\Unit\Index;

use PHPUnit\Framework\TestCase;
use Vusys\Exocomp\Index\Index;

/**
 * From a checkout, the index file is keyed by the extractor's source as well as the version,
 * so changing what the indexer records cannot leave an index of the old extraction in use (#116).
 */
final class DevIndexKeyTest extends TestCase
{
    public function test_a_checkout_keys_the_index_by_the_extractor_source(): void
    {
        if (! is_dir(dirname(__DIR__, 3).'/.git')) {
            $this->markTestSkipped('not running from a git checkout');
        }

        $this->assertMatchesRegularExpression('/-dev[0-9a-f]{8}-/', basename(Index::cachePath()));
    }
}

<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php55\Rector\String_\StringClassNameToClassConstantRector;
use Rector\Set\ValueObject\SetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    // Fixtures pin expected output byte-for-byte and deliberately contain constructs Rector
    // would "modernise" away — which would silently delete the thing under test.
    ->withSkip([
        __DIR__.'/tests/Fixtures',

        // Class-name *strings* are this tool's input data, not references to classes it can
        // load: a symbol address like 'App\Models\Order' names something in the codebase under
        // analysis, which by definition is not autoloadable from here. Rewriting them to
        // `::class` constants makes the code stop compiling — or, worse, silently changes what
        // a test feeds the tool.
        StringClassNameToClassConstantRector::class,
    ])
    ->withPhpSets(php84: true)
    ->withSets([
        SetList::CODE_QUALITY,
        SetList::DEAD_CODE,
        SetList::TYPE_DECLARATION,
        SetList::PRIVATIZATION,
    ]);

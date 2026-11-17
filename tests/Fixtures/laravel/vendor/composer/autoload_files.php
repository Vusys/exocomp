<?php

/*
 * Composer's list of files to load on every request: where installed packages declare their
 * global helper functions. The tool reads the functions in them to type a call like `request()`.
 */

$vendorDir = dirname(__DIR__);

return [
    'fixture-helpers' => $vendorDir.'/illuminate/src/Foundation/helpers.php',
];

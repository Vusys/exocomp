<?php

// Shaped exactly like Composer's own dump: absolute paths built from $baseDir.

$vendorDir = dirname(dirname(__FILE__));
$baseDir = dirname($vendorDir);

return array(
    'Installed\\Legacy\\Mapped' => $baseDir . '/lib/legacy.php',
);

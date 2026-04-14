<?php

declare(strict_types=1);

// Define BASE_PATH for PHPStan static analysis.
// At runtime this is set by public/index.php and fw CLI.
if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__);
}

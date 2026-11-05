<?php
// Bootstrap for PHPUnit tests
// This file is loaded before each test

// Ensure we're running from the tests directory
if (!defined('TEST_BOOTSTRAP')) {
    define('TEST_BOOTSTRAP', true);
    
    // Set up autoloading for source files
    $srcDir = __DIR__ . '/../src';
    
    // Find all PHP files in src/ and require them
    $files = glob($srcDir . '/*.php');
    if ($files === false) {
        $files = [];
    }
    
    foreach ($files as $file) {
        // Skip endpoint files (they have HTTP dependencies)
        $basename = basename($file);
        if (strpos($basename, 'index.php') === 0 || 
            strpos($basename, 'keystroke.php') === 0 ||
            strpos($basename, 'probe.php') === 0) {
            continue;
        }
        require_once $file;
    }
}

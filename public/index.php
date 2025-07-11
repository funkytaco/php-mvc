<?php

use Nimbus\Core\Application;

require_once __DIR__ . '/../vendor/autoload.php';

// Initialize Nimbus Application
$app = new Application([
    'env' => 'development',
    'app_dir' => __DIR__ . '/../app',
    'src_dir' => __DIR__ . '/../src',
    'vendor_dir' => __DIR__ . '/../vendor',
    'public_dir' => 'public',
    'config_file' => __DIR__ . '/../app/app.config.php'
]);

// Run the application
$app->run();
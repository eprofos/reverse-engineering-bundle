<?php

declare(strict_types=1);

use Eprofos\ReverseEngineeringBundle\Service\MySQLTypeMapper;

require dirname(__DIR__) . '/vendor/autoload.php';

// Register custom MySQL types early in bootstrap
MySQLTypeMapper::registerCustomTypes();

if (file_exists(dirname(__DIR__) . '/config/bootstrap.php')) {
    require dirname(__DIR__) . '/config/bootstrap.php';
}

// Test-specific configuration
if (isset($_SERVER['APP_DEBUG']) && $_SERVER['APP_DEBUG']) {
    umask(0o000);
}

// Configuration for tests
if (isset($_SERVER['APP_ENV']) && $_SERVER['APP_ENV'] === 'test') {
    // Increase limits for integration tests
    ini_set('memory_limit', $_ENV['PHPUNIT_MEMORY_LIMIT'] ?? '256M');
    ini_set('max_execution_time', '300');

    // Timezone configuration
    if (isset($_ENV['PHP_TIMEZONE'])) {
        date_default_timezone_set($_ENV['PHP_TIMEZONE']);
    }
}

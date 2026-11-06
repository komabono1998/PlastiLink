<?php
declare(strict_types=1);

/**
 * Shared bootstrap for the web entry point and CLI tools.
 * Loads the autoloader, helpers, config, timezone and error handling.
 */

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/app/Core/Autoloader.php';
App\Core\Autoloader::register(BASE_PATH . '/app');

require BASE_PATH . '/app/Helpers/functions.php';

App\Core\Config::load(BASE_PATH . '/config');
date_default_timezone_set((string) App\Core\Config::get('app.timezone', 'Africa/Kampala'));
mb_internal_encoding('UTF-8');

App\Core\ErrorHandler::register();

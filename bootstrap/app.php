<?php

declare(strict_types=1);

use NovaNuke\Core\Application;

define('NOVANUKE_START', microtime(true));
define('NOVANUKE_ROOT', dirname(__DIR__));

require_once NOVANUKE_ROOT . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(NOVANUKE_ROOT);
$dotenv->safeLoad();

return Application::create(NOVANUKE_ROOT);

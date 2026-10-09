<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';

if (! is_file($autoload)) {
    fwrite(STDERR, "Composer dependencies are required. Run composer install before integration tests." . PHP_EOL);
    exit(1);
}

require $autoload;

$testingEnvironment = $root . '/.env.testing';
if (is_file($testingEnvironment)) {
    Dotenv\Dotenv::createImmutable($root, '.env.testing')->safeLoad();
}

if (! extension_loaded('pdo_mysql')) {
    fwrite(STDERR, "The pdo_mysql extension is required for MySQL integration tests." . PHP_EOL);
    exit(1);
}

$_ENV['NOVANUKE_RUN_INTEGRATION'] = '1';
$_SERVER['NOVANUKE_RUN_INTEGRATION'] = '1';
putenv('NOVANUKE_RUN_INTEGRATION=1');

$phpunit = $root . '/vendor/phpunit/phpunit/phpunit';
if (! is_file($phpunit)) {
    fwrite(STDERR, "PHPUnit is required. Run composer install before integration tests." . PHP_EOL);
    exit(1);
}

$arguments = [
    $phpunit,
    '--configuration',
    $root . '/phpunit.xml.dist',
    '--testsuite',
    'Integration',
    '--bootstrap',
    $autoload,
    '--do-not-cache-result',
];

$command = escapeshellarg(PHP_BINARY);
foreach ($arguments as $argument) {
    $command .= ' ' . escapeshellarg($argument);
}

$process = proc_open($command, [
    0 => STDIN,
    1 => STDOUT,
    2 => STDERR,
], $pipes, $root);

if (! is_resource($process)) {
    fwrite(STDERR, "Unable to start PHPUnit." . PHP_EOL);
    exit(1);
}

$exitCode = proc_close($process);
exit($exitCode);

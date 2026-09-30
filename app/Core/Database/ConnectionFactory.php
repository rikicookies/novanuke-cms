<?php

declare(strict_types=1);

namespace NovaNuke\Core\Database;

use NovaNuke\Core\Config\ConfigRepository;
use PDO;
use RuntimeException;

final class ConnectionFactory
{
    public function __construct(private readonly ConfigRepository $config)
    {
    }

    public function create(): PDO
    {
        $driver = $this->config->get('database.driver');

        if ($driver !== 'mysql') {
            throw new RuntimeException('NovaNuke currently supports only MySQL/MariaDB.');
        }

        $host = $this->config->get('database.host');
        $port = $this->config->get('database.port');
        $database = $this->config->get('database.database');
        $charset = $this->config->get('database.charset', 'utf8mb4');
        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";

        return new PDO(
            $dsn,
            (string) $this->config->get('database.username'),
            (string) $this->config->get('database.password'),
            (array) $this->config->get('database.options', []),
        );
    }
}

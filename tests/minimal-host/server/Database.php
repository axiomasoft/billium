<?php

declare(strict_types=1);

namespace BilliumMinimalHost;

use PDO;
use RuntimeException;

/** A new database per proof keeps the full module schema out of ledger-only tests. */
final class Database
{
    public static function create(): string
    {
        $name = 'billium_server_'.bin2hex(random_bytes(8)).'_test';
        $admin = self::admin();
        $admin->exec('CREATE DATABASE '.self::identifier($name));

        try {
            $owner = self::connect($name);
            $runtime = self::identifier(self::runtimeRole());
            $owner->exec('REVOKE CREATE ON SCHEMA public FROM PUBLIC');
            $owner->exec('REVOKE TEMPORARY ON DATABASE '.self::identifier($name).' FROM PUBLIC');
            $owner->exec('GRANT CONNECT ON DATABASE '.self::identifier($name).' TO '.$runtime);
            $owner->exec('GRANT USAGE ON SCHEMA public TO '.$runtime);
            $owner->exec('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT ON TABLES TO '.$runtime);
            $owner->exec('ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE EXECUTE ON FUNCTIONS FROM PUBLIC');
        } catch (\Throwable $error) {
            self::drop($name);
            throw $error;
        }

        return $name;
    }

    public static function drop(string $name): void
    {
        if (preg_match('/^billium_server_[0-9a-f]{16}_test$/D', $name) !== 1) {
            throw new RuntimeException('Expected a disposable server test database');
        }

        self::admin()->exec('DROP DATABASE '.self::identifier($name).' WITH (FORCE)');
    }

    public static function runtimeRole(): string
    {
        return self::env('DB_USERNAME', 'billium_runtime');
    }

    /** @return array<string, string> */
    public static function connection(string $database, bool $owner = false): array
    {
        return [
            'driver' => 'pgsql',
            'host' => self::env('DB_HOST', '127.0.0.1'),
            'port' => self::env('DB_PORT', '54329'),
            'database' => $database,
            'username' => $owner ? self::env('DB_OWNER_USERNAME', 'billium') : self::runtimeRole(),
            'password' => $owner ? self::env('DB_OWNER_PASSWORD', 'billium') : self::env('DB_PASSWORD', 'billium_runtime'),
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
        ];
    }

    private static function admin(): PDO
    {
        $database = self::env('DB_DATABASE', 'billium_test');
        if (! str_ends_with($database, '_test') || self::connection($database, owner: true)['username'] === self::runtimeRole()) {
            throw new RuntimeException('Server proofs require a test stand with separate database owner and runtime roles');
        }

        return self::connect($database);
    }

    private static function connect(string $database): PDO
    {
        $config = self::connection($database, owner: true);

        return new PDO(
            sprintf('pgsql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], $config['database']),
            $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }

    private static function identifier(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }

    private static function env(string $name, string $default): string
    {
        $value = getenv($name);

        return $value === false ? $default : $value;
    }
}

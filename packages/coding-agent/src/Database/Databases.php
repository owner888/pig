<?php

declare(strict_types=1);

namespace Pig\CodingAgent\Database;

use PDO;
use PDOException;
use Pig\Agent\AgentError;

/** Opens a `Database` from a DSN: `mysql:host=…;dbname=…` or `sqlite:/path/to/file.db`. */
final class Databases
{
    /**
     * @param string $prefix put in front of each table's name
     * @throws AgentError saying what is missing when the driver is not installed or the database
     *         cannot be reached; never a quiet fall back to files
     */
    public static function open(string $dsn, ?string $user = null, ?string $password = null, string $prefix = 'pig_'): Database
    {
        $driver = strtolower(strstr($dsn, ':', true) ?: '');
        $extension = match ($driver) {
            'mysql' => 'pdo_mysql',
            'sqlite' => 'pdo_sqlite',
            default => throw new AgentError("The storage setting is `jsonl`, a `mysql:` DSN or a `sqlite:` path, not {$dsn}"),
        };

        if (!in_array($driver, PDO::getAvailableDrivers(), true)) {
            throw new AgentError("Sessions in {$driver} need PHP's {$extension} extension, which this PHP does not have.");
        }

        if ($driver === 'sqlite') {
            $file = substr($dsn, 7);
            $directory = dirname($file);

            if ($file !== '' && $file !== ':memory:' && !is_dir($directory) && !mkdir($directory, 0o700, true) && !is_dir($directory)) {
                throw new AgentError("Could not create {$directory} for the database");
            }
        }

        $connect = static fn (): PDO => new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        try {
            return $driver === 'mysql' ? new MysqlDatabase($connect, $prefix) : new SqliteDatabase($connect, $prefix);
        } catch (PDOException $error) {
            throw new AgentError("Could not open the database ({$driver}): {$error->getMessage()}", previous: $error);
        }
    }
}

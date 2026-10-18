<?php

declare(strict_types=1);

namespace Hunter\Db;

use Hunter\Support\Config;
use PDO;
use PDOException;
use Throwable;

/**
 * One PDO connection per request (database-design.md §11): exceptions on error,
 * native prepared statements, utf8mb4, UTC, strict SQL mode, READ COMMITTED.
 */
final class Database
{
    private ?PDO $pdo = null;

    public function __construct(private Config $config)
    {
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $c = $this->config;
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c->get('DB_HOST'), (int)$c->get('DB_PORT'), $c->get('DB_NAME'));
            $pdo = new PDO($dsn, $c->get('DB_USER'), $c->get('DB_PASSWORD'), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
            $pdo->exec("SET SESSION time_zone = '+00:00'");
            $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION'");
            $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $this->pdo = $pdo;
        }
        return $this->pdo;
    }

    /** @return array<string,mixed>|null */
    public function one(string $sql, array $params = []): ?array
    {
        $st = $this->pdo()->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    /** @return list<array<string,mixed>> */
    public function all(string $sql, array $params = []): array
    {
        $st = $this->pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public function run(string $sql, array $params = []): int
    {
        $st = $this->pdo()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    public function lastId(): int
    {
        return (int)$this->pdo()->lastInsertId();
    }

    /** Run $fn in a transaction; roll back on any exception. */
    public function tx(callable $fn): mixed
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * tx() that re-runs the whole transaction (up to $attempts times) when InnoDB
     * chose it as a deadlock victim (1213). The victim was fully rolled back, so a
     * re-run starts from a clean state. Used by administrator content mutations.
     */
    public function txRetry(callable $fn, int $attempts = 3): mixed
    {
        for ($i = 1; ; $i++) {
            try {
                return $this->tx($fn);
            } catch (PDOException $e) {
                if (($e->errorInfo[1] ?? 0) !== 1213 || $i >= $attempts) {
                    throw $e;
                }
            }
        }
    }

    public static function isDuplicateKey(PDOException $e): bool
    {
        return ($e->errorInfo[1] ?? 0) === 1062;
    }
}

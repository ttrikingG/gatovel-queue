<?php

declare(strict_types=1);

namespace Gatovel\Queue\idempotency;

use Closure;
use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class IdempotencyStore
{
    public function __construct(
        private readonly PDO $connection
    ) {
        if ($connection->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            throw new InvalidArgumentException(
                'IdempotencyStore currently supports SQLite only.'
            );
        }

        $this->connection->setAttribute(
            PDO::ATTR_ERRMODE,
            PDO::ERRMODE_EXCEPTION
        );

        $this->connection->exec('PRAGMA busy_timeout = 5000');
    }

    public function createTable(): void
    {
        $this->connection->exec(
            'CREATE TABLE IF NOT EXISTS queue_idempotency (
                idempotency_key TEXT PRIMARY KEY,
                completed_at INTEGER NOT NULL
            )'
        );
    }

    public function isCompleted(string $key): bool
    {
        $this->validateKey($key);

        $statement = $this->connection->prepare(
            'SELECT 1
             FROM queue_idempotency
             WHERE idempotency_key = :key'
        );

        $statement->execute(['key' => $key]);

        return $statement->fetchColumn() !== false;
    }

    public function markCompleted(string $key): bool
    {
        $this->validateKey($key);

        $statement = $this->connection->prepare(
            'INSERT OR IGNORE INTO queue_idempotency (
                idempotency_key,
                completed_at
            ) VALUES (
                :key,
                :completed_at
            )'
        );

        $statement->execute([
            'key' => $key,
            'completed_at' => time(),
        ]);

        return $statement->rowCount() === 1;
    }

    public function executeOnce(
        string $key,
        Closure $operation
    ): bool {
        $this->validateKey($key);

        if ($this->connection->inTransaction()) {
            throw new RuntimeException(
                'executeOnce cannot run inside an existing transaction.'
            );
        }

        $transactionStarted = false;

        try {
            $this->connection->exec('BEGIN IMMEDIATE TRANSACTION');
            $transactionStarted = true;

            if ($this->isCompleted($key)) {
                $this->connection->exec('COMMIT');
                $transactionStarted = false;

                return false;
            }

            $operation($this->connection);

            if (!$this->markCompleted($key)) {
                throw new RuntimeException(
                    'Unable to register idempotency key.'
                );
            }

            $this->connection->exec('COMMIT');
            $transactionStarted = false;

            return true;
        } catch (Throwable $exception) {
            if ($transactionStarted) {
                try {
                    $this->connection->exec('ROLLBACK');
                } catch (PDOException) {
                    // Preserve the original exception.
                }
            }

            throw $exception;
        }
    }

    private function validateKey(string $key): void
    {
        if (trim($key) === '') {
            throw new InvalidArgumentException(
                'Idempotency key cannot be empty.'
            );
        }
    }
}

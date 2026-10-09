<?php

declare(strict_types=1);

namespace Gatovel\Queue\failed;

use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class FailedJobRepository
{
    public function __construct(
        private readonly PDO $connection,
        private readonly int $retryAfter = 60
    ) {
        if ($retryAfter < 1) {
            throw new InvalidArgumentException(
                'Retry timeout must be greater than zero.'
            );
        }

        if ($connection->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            throw new InvalidArgumentException(
                'FailedJobRepository currently supports SQLite only.'
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
            'CREATE TABLE IF NOT EXISTS queue_failed_jobs (
                id TEXT PRIMARY KEY,
                queue TEXT NOT NULL,
                job TEXT NOT NULL,
                payload TEXT NOT NULL,
                attempts INTEGER NOT NULL,
                exception TEXT NOT NULL,
                failed_at INTEGER NOT NULL
            )'
        );
    }

    public function fail(
        array $job,
        string $reservationToken,
        Throwable $exception
    ): void {
        $transactionStarted = false;

        try {
            $this->connection->exec('BEGIN IMMEDIATE TRANSACTION');
            $transactionStarted = true;

            $now = time();
            $expired = $now - $this->retryAfter;

            $statement = $this->connection->prepare(
                'SELECT *
                 FROM queue_jobs
                 WHERE id = :id
                   AND reservation_token = :token
                   AND reserved_at IS NOT NULL
                   AND reserved_at > :expired'
            );

            $statement->execute([
                'id' => $job['id'],
                'token' => $reservationToken,
                'expired' => $expired,
            ]);

            $reserved = $statement->fetch(PDO::FETCH_ASSOC);

            if ($reserved === false) {
                throw new RuntimeException(
                    'Job reservation is no longer valid.'
                );
            }

            $insert = $this->connection->prepare(
                'INSERT INTO queue_failed_jobs (
                    id, queue, job, payload,
                    attempts, exception, failed_at
                ) VALUES (
                    :id, :queue, :job, :payload,
                    :attempts, :exception, :failed_at
                )'
            );

            $insert->execute([
                'id' => $reserved['id'],
                'queue' => $reserved['queue'],
                'job' => $reserved['job'],
                'payload' => $reserved['payload'],
                'attempts' => (int) $reserved['attempts'],
                'exception' => get_class($exception)
                    . ': ' . $exception->getMessage(),
                'failed_at' => $now,
            ]);

            $delete = $this->connection->prepare(
                'DELETE FROM queue_jobs
                 WHERE id = :id
                   AND reservation_token = :token
                   AND reserved_at IS NOT NULL
                   AND reserved_at > :expired'
            );

            $delete->execute([
                'id' => $reserved['id'],
                'token' => $reservationToken,
                'expired' => $expired,
            ]);

            if ($delete->rowCount() !== 1) {
                throw new RuntimeException(
                    'Unable to remove permanently failed job.'
                );
            }

            $this->connection->exec('COMMIT');
            $transactionStarted = false;
        } catch (Throwable $error) {
            if ($transactionStarted) {
                try {
                    $this->connection->exec('ROLLBACK');
                } catch (PDOException) {
                    // Preserve the original exception.
                }
            }

            throw $error;
        }
    }

    public function all(): array
    {
        return $this->connection
            ->query(
                'SELECT * FROM queue_failed_jobs
                 ORDER BY failed_at DESC'
            )
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find(string $id): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT * FROM queue_failed_jobs
             WHERE id = :id'
        );

        $statement->execute(['id' => $id]);

        $result = $statement->fetch(PDO::FETCH_ASSOC);

        return $result === false ? null : $result;
    }

    public function delete(string $id): bool
    {
        $statement = $this->connection->prepare(
            'DELETE FROM queue_failed_jobs
             WHERE id = :id'
        );

        $statement->execute(['id' => $id]);

        return $statement->rowCount() > 0;
    }
}

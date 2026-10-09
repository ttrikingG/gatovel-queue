<?php

declare(strict_types=1);

namespace Gatovel\Queue\drivers;

use Gatovel\Queue\contracts\QueueInterface;
use Gatovel\Queue\contracts\ReservationRenewalInterface;
use InvalidArgumentException;
use PDO;
use PDOException;
use Throwable;

class DatabaseDriver implements QueueInterface, ReservationRenewalInterface
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

        if ($this->connection->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            throw new InvalidArgumentException(
                'DatabaseDriver currently supports SQLite only.'
            );
        }

        $this->connection->setAttribute(
            PDO::ATTR_ERRMODE,
            PDO::ERRMODE_EXCEPTION
        );

        $this->connection->setAttribute(
            PDO::ATTR_DEFAULT_FETCH_MODE,
            PDO::FETCH_ASSOC
        );

        $this->connection->exec('PRAGMA busy_timeout = 5000');
    }

    public function createTable(): void
    {
        $this->connection->exec(
            "CREATE TABLE IF NOT EXISTS queue_jobs (
                id TEXT PRIMARY KEY,
                queue TEXT NOT NULL DEFAULT 'default',
                job TEXT NOT NULL,
                payload TEXT NOT NULL,
                attempts INTEGER NOT NULL DEFAULT 0,
                available_at INTEGER NOT NULL,
                reserved_at INTEGER DEFAULT NULL,
                reservation_token TEXT DEFAULT NULL,
                created_at INTEGER NOT NULL
            )"
        );

        $columns = $this->connection
            ->query('PRAGMA table_info(queue_jobs)')
            ->fetchAll(PDO::FETCH_ASSOC);

        $columnNames = array_column($columns, 'name');

        if (!in_array('reservation_token', $columnNames, true)) {
            $this->connection->exec(
                'ALTER TABLE queue_jobs
                 ADD COLUMN reservation_token TEXT DEFAULT NULL'
            );
        }

        $this->connection->exec(
            'CREATE INDEX IF NOT EXISTS queue_jobs_available_idx
             ON queue_jobs (queue, available_at, reserved_at)'
        );
    }

    public function push(
        string $job,
        array $payload = [],
        int $delay = 0
    ): string {
        if (trim($job) === '') {
            throw new InvalidArgumentException(
                'Job name cannot be empty.'
            );
        }

        if ($delay < 0) {
            throw new InvalidArgumentException(
                'Delay cannot be negative.'
            );
        }

        $id = bin2hex(random_bytes(16));
        $now = time();

        $statement = $this->connection->prepare(
            'INSERT INTO queue_jobs (
                id, queue, job, payload, attempts,
                available_at, reserved_at, reservation_token,
                created_at
            ) VALUES (
                :id, :queue, :job, :payload, 0,
                :available_at, NULL, NULL, :created_at
            )'
        );

        $statement->execute([
            'id' => $id,
            'queue' => 'default',
            'job' => $job,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'available_at' => $now + $delay,
            'created_at' => $now,
        ]);

        return $id;
    }

    public function pop(
        string $queue = 'default'
    ): ?array {
        $now = time();
        $transactionStarted = false;

        try {
            $this->connection->exec('BEGIN IMMEDIATE TRANSACTION');
            $transactionStarted = true;

            $statement = $this->connection->prepare(
                'SELECT *
                 FROM queue_jobs
                 WHERE queue = :queue
                   AND available_at <= :now
                   AND (
                       reserved_at IS NULL
                       OR reserved_at <= :expired
                   )
                 ORDER BY available_at ASC, created_at ASC, id ASC
                 LIMIT 1'
            );

            $statement->execute([
                'queue' => $queue,
                'now' => $now,
                'expired' => $now - $this->retryAfter,
            ]);

            $job = $statement->fetch(PDO::FETCH_ASSOC);

            if ($job === false) {
                $this->connection->exec('COMMIT');
                $transactionStarted = false;

                return null;
            }

            $token = bin2hex(random_bytes(32));

            $update = $this->connection->prepare(
                'UPDATE queue_jobs
                 SET reserved_at = :reserved_at,
                     reservation_token = :token,
                     attempts = attempts + 1
                 WHERE id = :id'
            );

            $update->execute([
                'reserved_at' => $now,
                'token' => $token,
                'id' => $job['id'],
            ]);

            $payload = json_decode(
                $job['payload'],
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            $this->connection->exec('COMMIT');
            $transactionStarted = false;

            return [
                'id' => $job['id'],
                'queue' => $job['queue'],
                'job' => $job['job'],
                'payload' => $payload,
                'attempts' => (int) $job['attempts'] + 1,
                'reservation_token' => $token,
            ];
        } catch (Throwable $exception) {
            if ($transactionStarted) {
                try {
                    $this->connection->exec('ROLLBACK');
                } catch (PDOException) {
                    // Preserva a exceção original.
                }
            }

            throw $exception;
        }
    }

    public function delete(
        string $jobId,
        string $reservationToken
    ): bool {
        $now = time();

        $statement = $this->connection->prepare(
            'DELETE FROM queue_jobs
             WHERE id = :id
               AND reservation_token = :token
               AND reserved_at IS NOT NULL
               AND reserved_at > :expired'
        );

        $statement->execute([
            'id' => $jobId,
            'token' => $reservationToken,
            'expired' => $now - $this->retryAfter,
        ]);

        return $statement->rowCount() > 0;
    }

    public function release(
        string $jobId,
        string $reservationToken,
        int $delay = 0
    ): bool {
        if ($delay < 0) {
            throw new InvalidArgumentException(
                'Delay cannot be negative.'
            );
        }

        $now = time();

        $statement = $this->connection->prepare(
            'UPDATE queue_jobs
             SET reserved_at = NULL,
                 reservation_token = NULL,
                 available_at = :available_at
             WHERE id = :id
               AND reservation_token = :token
               AND reserved_at IS NOT NULL
               AND reserved_at > :expired'
        );

        $statement->execute([
            'available_at' => $now + $delay,
            'id' => $jobId,
            'token' => $reservationToken,
            'expired' => $now - $this->retryAfter,
        ]);

        return $statement->rowCount() > 0;
    }

    public function renew(
        string $jobId,
        string $reservationToken
    ): bool {
        $now = time();

        $statement = $this->connection->prepare(
            'UPDATE queue_jobs
             SET reserved_at = :now
             WHERE id = :id
               AND reservation_token = :token
               AND reserved_at IS NOT NULL
               AND reserved_at > :expired'
        );

        $statement->execute([
            'now' => $now,
            'id' => $jobId,
            'token' => $reservationToken,
            'expired' => $now - $this->retryAfter,
        ]);

        return $statement->rowCount() > 0;
    }
}

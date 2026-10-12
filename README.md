
# Gatovel Queue

A modular and independent job queue library for PHP 8.3+, developed as part of the Gatovel Framework ecosystem.

Gatovel Queue provides persistent job storage, background workers, retry policies, process isolation, reservation management, failed job tracking, and transactional idempotency.

## Features

- Persistent SQLite-backed job queues
- Immediate and delayed job dispatching
- Job reservation tokens
- Multiple worker support
- Reservation expiration and recovery
- Configurable retry policies
- Exponential backoff
- Permanent failure tracking
- Synchronous job execution
- Isolated PHP process execution
- Automatic reservation heartbeat
- Job execution timeouts
- Transactional idempotency
- Modular interfaces and extensible architecture
- Composer PSR-4 autoloading

## Requirements

- PHP 8.3 or higher
- Composer
- PDO SQLite extension
- PHP CLI
- `proc_open` enabled for isolated process execution

## Installation

Install the package using Composer:

```bash
composer require gatovel/queue
```

Include Composer's autoloader:

```php
require __DIR__ . '/vendor/autoload.php';
```

## Architecture

The package is organized into independent components:

```text
src/
├── contracts/
│   ├── JobExecutorInterface.php
│   ├── JobInterface.php
│   ├── QueueInterface.php
│   └── ReservationRenewalInterface.php
├── drivers/
│   └── DatabaseDriver.php
├── exceptions/
│   ├── JobTimeoutException.php
│   ├── PermanentJobException.php
│   └── ReservationLostException.php
├── executors/
│   ├── ProcessJobExecutor.php
│   ├── SyncJobExecutor.php
│   └── process_job.php
├── failed/
│   └── FailedJobRepository.php
├── idempotency/
│   └── IdempotencyStore.php
├── jobs/
├── retry/
│   └── RetryPolicy.php
└── workers/
    ├── Worker.php
    └── WorkerRunner.php
```

## Database configuration

Gatovel Queue currently supports SQLite through PDO.

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Gatovel\Queue\drivers\DatabaseDriver;
use Gatovel\Queue\failed\FailedJobRepository;

$pdo = new PDO(
    'sqlite:' . __DIR__ . '/queue.sqlite'
);

$queue = new DatabaseDriver($pdo);
$queue->createTable();

$failedJobs = new FailedJobRepository($pdo);
$failedJobs->createTable();
```

The queue driver creates the `queue_jobs` table.

The failed job repository creates the `queue_failed_jobs` table.

Both components can use the same PDO connection.

## Creating a job

Every job must implement `JobInterface`.

Example: `src/Jobs/SendWelcomeEmail.php`

```php
<?php

declare(strict_types=1);

namespace App\Jobs;

use Gatovel\Queue\contracts\JobInterface;

final class SendWelcomeEmail implements JobInterface
{
    public function handle(array $payload): void
    {
        $email = $payload['email'];

        echo "Processing welcome email for: {$email}\n";

        // Implement your email delivery logic here.
    }
}
```

The application must configure Composer autoloading for the `App\` namespace.

For example:

```json
{
    "autoload": {
        "psr-4": {
            "App\\": "src/"
        }
    }
}
```

After configuring autoloading, run:

```bash
composer dump-autoload
```

## Dispatching jobs

Use `push()` to add a job to the queue.

```php
use App\Jobs\SendWelcomeEmail;

$jobId = $queue->push(
    SendWelcomeEmail::class,
    [
        'email' => 'user@example.com'
    ]
);
```

The method returns a unique job identifier.

### Delayed jobs

The third argument defines the execution delay in seconds.

```php
$jobId = $queue->push(
    SendWelcomeEmail::class,
    [
        'email' => 'user@example.com'
    ],
    30
);
```

This job becomes available after approximately 30 seconds.

## Processing jobs

Create a worker:

```php
use Gatovel\Queue\workers\Worker;

$worker = new Worker(
    queue: $queue,
    failedJobs: $failedJobs
);
```

Process one available job:

```php
$processed = $worker->runOnce();
```

The method returns `true` when a job is successfully processed and `false` when no job is available.

Execution failures can throw exceptions.

## Running a continuous worker

Use `WorkerRunner` to process jobs continuously.

```php
use Gatovel\Queue\workers\WorkerRunner;

$runner = new WorkerRunner(
    worker: $worker,
    sleepSeconds: 2
);

$runner->run();
```

The runner waits between iterations when no job is available or an execution error occurs.

### Limited execution

```php
$runner->run(
    queue: 'default',
    maxIterations: 10
);
```

This executes a maximum of 10 worker iterations, not necessarily 10 jobs.

For production environments, use an external process supervisor to manage long-running workers.

## Retry policy

Gatovel Queue supports configurable retry attempts and exponential backoff.

```php
use Gatovel\Queue\retry\RetryPolicy;
use Gatovel\Queue\workers\Worker;

$retryPolicy = new RetryPolicy(
    maxAttempts: 3,
    baseDelay: 5,
    maxDelay: 300
);

$worker = new Worker(
    queue: $queue,
    failedJobs: $failedJobs,
    retryPolicy: $retryPolicy
);
```

Default configuration:

| Setting | Default |
|---|---|
| Maximum attempts | 3 |
| Initial retry delay | 5 seconds |
| Maximum retry delay | 300 seconds |

The retry delay increases exponentially:

```text
Attempt 1 fails → retry after 5 seconds
Attempt 2 fails → retry after 10 seconds
Attempt 3 fails → permanent failure
```

After the configured attempts are exhausted, the job is transferred to the failed job repository.

Jobs that throw `PermanentJobException` bypass additional retries.

## Failed job management

Failed jobs can be stored and inspected.

```php
use Gatovel\Queue\failed\FailedJobRepository;

$failedJobs = new FailedJobRepository($pdo);

$failedJobs->createTable();
```

List all failed jobs:

```php
$failures = $failedJobs->all();
```

Find a failed job:

```php
$failure = $failedJobs->find($jobId);
```

Delete a failed job record:

```php
$deleted = $failedJobs->delete($jobId);
```

The repository stores job information, payload, attempts, exception details, and failure timestamp.

Deleting a failed job record does not automatically redispatch it.

## Synchronous execution

The default worker uses `SyncJobExecutor`.

```php
use Gatovel\Queue\executors\SyncJobExecutor;
use Gatovel\Queue\workers\Worker;

$executor = new SyncJobExecutor();

$worker = new Worker(
    queue: $queue,
    failedJobs: $failedJobs,
    executor: $executor
);
```

The job executes inside the worker's PHP process.

### Custom job factory

The synchronous executor supports custom job instantiation.

```php
$executor = new SyncJobExecutor(
    jobFactory: function (string $className): object {
        return new $className();
    }
);
```

This can be integrated with an application's dependency injection container.

## Isolated process execution

Gatovel Queue also supports running jobs in separate PHP processes.

```php
use Gatovel\Queue\executors\ProcessJobExecutor;
use Gatovel\Queue\workers\Worker;

$executor = new ProcessJobExecutor(
    renewal: $queue,
    heartbeatSeconds: 5,
    timeoutSeconds: 30
);

$worker = new Worker(
    queue: $queue,
    failedJobs: $failedJobs,
    executor: $executor
);
```

The executor starts a child PHP process using `proc_open`.

This allows job execution to be isolated from the main worker process.

Requirements for isolated jobs:

- The PHP CLI executable must be available.
- `proc_open` must be enabled.
- The job class must be discoverable through Composer autoloading.
- The job class must be instantiable without constructor arguments.
- The job must implement `JobInterface`.

The process executor does not use the synchronous executor's custom job factory.

## Automatic heartbeat

Long-running jobs can periodically renew their reservations.

```php
$executor = new ProcessJobExecutor(
    renewal: $queue,
    heartbeatSeconds: 5
);
```

The executor attempts to renew the job reservation every five seconds while the child process is running.

If the reservation cannot be renewed, the executor raises `ReservationLostException`.

The worker does not treat this exception as a normal retryable job failure.

## Job timeout

The process executor supports execution time limits.

```php
$executor = new ProcessJobExecutor(
    renewal: $queue,
    heartbeatSeconds: 5,
    timeoutSeconds: 60
);
```

When the execution time limit is exceeded, the executor raises `JobTimeoutException` and attempts to terminate the child process.

Setting `timeoutSeconds` to `0` disables the execution timeout.

## Reservation management

The database driver uses reservation tokens to coordinate job processing.

```php
$queue = new DatabaseDriver(
    connection: $pdo,
    retryAfter: 60
);
```

The `retryAfter` value defines the reservation expiration period in seconds.

Expired reservations become eligible for recovery by another worker.

Successful jobs are removed only when the worker acknowledges them using the corresponding reservation token.

When configuring `FailedJobRepository`, use the same reservation expiration period:

```php
$failedJobs = new FailedJobRepository(
    connection: $pdo,
    retryAfter: 60
);
```

For long-running jobs, use the process executor's heartbeat support and configure reservation timeouts appropriately.

## Idempotency

Idempotency prevents an already completed operation from being executed again with the same key.

```php
use Gatovel\Queue\idempotency\IdempotencyStore;

$idempotency = new IdempotencyStore($pdo);

$idempotency->createTable();
```

Check whether an operation has already completed:

```php
$completed = $idempotency->isCompleted('order:123');
```

Mark an operation as completed:

```php
$inserted = $idempotency->markCompleted('order:123');
```

### Transactional execution

Use `executeOnce()` to execute an operation within a SQLite transaction:

```php
$executed = $idempotency->executeOnce(
    'order:123',
    function (PDO $connection): void {
        $statement = $connection->prepare(
            'UPDATE orders SET processed = 1 WHERE id = :id'
        );

        $statement->execute([
            'id' => 123
        ]);
    }
);
```

The method returns:

- `true` when the operation executes and commits successfully.
- `false` when the idempotency key has already been completed.

If the operation fails, the transaction is rolled back.

Transactional guarantees apply to database operations performed through the supplied PDO connection. External side effects, such as sending emails or calling third-party APIs, are not automatically rolled back.

## Queue interface

The `QueueInterface` contract defines the following methods:

```php
public function push(
    string $job,
    array $payload = [],
    int $delay = 0
): string;

public function pop(
    string $queue = 'default'
): ?array;

public function delete(
    string $jobId,
    string $reservationToken
): bool;

public function release(
    string $jobId,
    string $reservationToken,
    int $delay = 0
): bool;
```

Custom queue drivers can implement this contract.

## Current limitations

- The built-in database driver currently supports SQLite only.
- Jobs are dispatched to the `default` queue by the current `push()` implementation.
- Job payloads must be JSON-encodable.
- Separate-process jobs must be instantiable without constructor arguments.
- The package does not include a built-in worker process supervisor.
- Idempotency of external operations must be handled separately.

## Testing

The project includes a local development test suite covering:

- Database queue operations
- Worker execution
- Concurrent workers
- Reservation expiration
- Retry policies
- Failed jobs
- Idempotency
- Transactional idempotency
- Automatic heartbeat
- Reservation renewal failures
- Process isolation
- Job timeouts
- Process termination

Run the tests in the development repository:

```bash
composer test
```

The local development suite contains 22 tests.

The `tests/` directory is excluded from the distributed source repository, so these tests are intended for the development checkout rather than consumer installations.

## License

Gatovel Queue is open-source software distributed under the MIT License.

See [LICENSE](LICENSE) for details.

## Gatovel Framework

Gatovel Queue is developed as an independent package within the Gatovel Framework ecosystem.

It can be used with Gatovel Framework or as a standalone library in other PHP applications.

**GitHub:** https://github.com/ttrikingG/gatovel-queue

**Packagist:** https://packagist.org/packages/gatovel/queue


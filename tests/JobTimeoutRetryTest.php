<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Gatovel\Queue\drivers\DatabaseDriver;
use Gatovel\Queue\executors\ProcessJobExecutor;
use Gatovel\Queue\exceptions\JobTimeoutException;
use Gatovel\Queue\jobs\SlowJob;
use Gatovel\Queue\retry\RetryPolicy;
use Gatovel\Queue\workers\Worker;

$databaseFile = tempnam(
    sys_get_temp_dir(),
    'gatovel-timeout-retry-'
);

if ($databaseFile === false) {
    throw new RuntimeException(
        'Unable to create temporary database.'
    );
}

try {
    $pdo = new PDO('sqlite:' . $databaseFile);

    $queue = new DatabaseDriver(
        connection: $pdo,
        retryAfter: 10
    );

    $queue->createTable();

    echo "1. Banco SQLite preparado.\n";

    $jobId = $queue->push(
        SlowJob::class,
        ['seconds' => 10]
    );

    echo "2. Job adicionado: {$jobId}\n";

    $executor = new ProcessJobExecutor(
        renewal: $queue,
        heartbeatSeconds: 1,
        timeoutSeconds: 2
    );

    $retryPolicy = new RetryPolicy(
        maxAttempts: 3,
        baseDelay: 1,
        maxDelay: 4
    );

    $worker = new Worker(
        queue: $queue,
        retryPolicy: $retryPolicy,
        executor: $executor
    );

    echo "3. Executando primeira tentativa...\n";

    $timeoutDetected = false;

    try {
        $worker->runOnce();
    } catch (JobTimeoutException $exception) {
        $timeoutDetected = true;

        echo "4. Timeout detectado corretamente.\n";
    }

    if (!$timeoutDetected) {
        throw new RuntimeException(
            'Expected JobTimeoutException.'
        );
    }

    $statement = $pdo->prepare(
        'SELECT attempts, reserved_at, reservation_token,
                available_at
         FROM queue_jobs
         WHERE id = :id'
    );

    $statement->execute(['id' => $jobId]);

    $record = $statement->fetch(PDO::FETCH_ASSOC);

    if ($record === false) {
        throw new RuntimeException(
            'Job was removed instead of being retried.'
        );
    }

    if ((int) $record['attempts'] !== 1) {
        throw new RuntimeException(
            'Expected one completed reservation attempt.'
        );
    }

    if ($record['reserved_at'] !== null) {
        throw new RuntimeException(
            'Job reservation was not released.'
        );
    }

    if ($record['reservation_token'] !== null) {
        throw new RuntimeException(
            'Reservation token was not cleared.'
        );
    }

    echo "5. Job liberado para retry.\n";
    echo "6. Tentativas registradas: 1.\n";

    // O backoff configurado é de um segundo.
    sleep(2);

    $reservedJob = $queue->pop();

    if ($reservedJob === null) {
        throw new RuntimeException(
            'Job was not available for retry.'
        );
    }

    if ($reservedJob['id'] !== $jobId) {
        throw new RuntimeException(
            'Unexpected job reserved.'
        );
    }

    if ((int) $reservedJob['attempts'] !== 2) {
        throw new RuntimeException(
            'Expected second reservation attempt.'
        );
    }

    echo "7. Job reservado novamente.\n";
    echo "8. Tentativas registradas: 2.\n";

    echo "\nTESTE DE TIMEOUT + RETRY CONCLUÍDO COM SUCESSO!\n";
} finally {
    unset($worker, $executor, $queue, $pdo);

    if (is_file($databaseFile)) {
        unlink($databaseFile);
    }
}

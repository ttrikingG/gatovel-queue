<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Gatovel\Queue\drivers\DatabaseDriver;
use Gatovel\Queue\executors\ProcessJobExecutor;
use Gatovel\Queue\exceptions\JobTimeoutException;
use Gatovel\Queue\failed\FailedJobRepository;
use Gatovel\Queue\jobs\SlowJob;
use Gatovel\Queue\retry\RetryPolicy;
use Gatovel\Queue\workers\Worker;

$databaseFile = tempnam(
    sys_get_temp_dir(),
    'gatovel-timeout-failed-'
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
        retryAfter: 15
    );

    $queue->createTable();

    $failedJobs = new FailedJobRepository(
        connection: $pdo,
        retryAfter: 15
    );

    $failedJobs->createTable();

    echo "1. Banco e Failed Jobs preparados.\n";

    $jobId = $queue->push(
        SlowJob::class,
        ['seconds' => 10]
    );

    echo "2. Job criado: {$jobId}\n";

    $executor = new ProcessJobExecutor(
        renewal: $queue,
        heartbeatSeconds: 1,
        timeoutSeconds: 1
    );

    $retryPolicy = new RetryPolicy(
        maxAttempts: 3,
        baseDelay: 1,
        maxDelay: 2
    );

    $worker = new Worker(
        queue: $queue,
        failedJobs: $failedJobs,
        retryPolicy: $retryPolicy,
        executor: $executor
    );

    for ($attempt = 1; $attempt <= 3; $attempt++) {
        echo "\n3. Executando tentativa {$attempt}...\n";

        $timeoutDetected = false;

        try {
            $worker->runOnce();
        } catch (JobTimeoutException $exception) {
            $timeoutDetected = true;

            echo "Timeout detectado na tentativa {$attempt}.\n";
        }

        if (!$timeoutDetected) {
            throw new RuntimeException(
                "Timeout not detected on attempt {$attempt}."
            );
        }

        if ($attempt < 3) {
            $statement = $pdo->prepare(
                'SELECT attempts, reserved_at,
                        reservation_token, available_at
                 FROM queue_jobs
                 WHERE id = :id'
            );

            $statement->execute(['id' => $jobId]);

            $record = $statement->fetch(PDO::FETCH_ASSOC);

            if ($record === false) {
                throw new RuntimeException(
                    'Job disappeared before final attempt.'
                );
            }

            if ((int) $record['attempts'] !== $attempt) {
                throw new RuntimeException(
                    'Unexpected attempt count.'
                );
            }

            if (
                $record['reserved_at'] !== null
                || $record['reservation_token'] !== null
            ) {
                throw new RuntimeException(
                    'Reservation was not released.'
                );
            }

            echo "Job liberado para retry.\n";

            // O backoff é de 1 segundo na primeira
            // tentativa e 2 segundos na segunda.
            $delay = $retryPolicy->backoff($attempt);

            sleep($delay + 1);
        }
    }

    echo "\n4. Verificando Failed Jobs...\n";

    $failed = $failedJobs->find($jobId);

    if ($failed === null) {
        throw new RuntimeException(
            'Job was not registered in Failed Jobs.'
        );
    }

    if ((int) $failed['attempts'] !== 3) {
        throw new RuntimeException(
            'Expected exactly three attempts.'
        );
    }

    if (
        !str_contains(
            $failed['exception'],
            JobTimeoutException::class
        )
    ) {
        throw new RuntimeException(
            'Incorrect exception registered in Failed Jobs.'
        );
    }

    echo "5. Job registrado em Failed Jobs.\n";
    echo "6. Tentativas registradas: "
        . $failed['attempts']
        . ".\n";

    $statement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM queue_jobs
         WHERE id = :id'
    );

    $statement->execute(['id' => $jobId]);

    if ((int) $statement->fetchColumn() !== 0) {
        throw new RuntimeException(
            'Failed job remains in the main queue.'
        );
    }

    echo "7. Job removido da fila principal.\n";

    if ($queue->pop() !== null) {
        throw new RuntimeException(
            'Main queue should be empty.'
        );
    }

    echo "8. Fila principal vazia confirmada.\n";

    echo "\nTESTE DE TIMEOUT + RETRY + FAILED JOBS CONCLUÍDO COM SUCESSO!\n";
} finally {
    unset($worker, $executor, $failedJobs, $queue, $pdo);

    if (is_file($databaseFile)) {
        unlink($databaseFile);
    }
}

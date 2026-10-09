<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Gatovel\Queue\drivers\DatabaseDriver;
use Gatovel\Queue\executors\ProcessJobExecutor;
use Gatovel\Queue\jobs\SlowJob;
use Gatovel\Queue\workers\Worker;

$databaseFile = tempnam(
    sys_get_temp_dir(),
    'gatovel-heartbeat-'
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
        retryAfter: 3
    );

    $queue->createTable();

    echo "1. Banco SQLite preparado.\n";

    $jobId = $queue->push(
        SlowJob::class,
        ['seconds' => 6]
    );

    echo "2. SlowJob adicionado à fila: {$jobId}\n";

    $executor = new ProcessJobExecutor(
        renewal: $queue,
        heartbeatSeconds: 1
    );

    $worker = new Worker(
        queue: $queue,
        executor: $executor
    );

    echo "3. Iniciando Worker com heartbeat automático...\n";

    $start = microtime(true);

    if (!$worker->runOnce()) {
        throw new RuntimeException(
            'Worker did not process the job.'
        );
    }

    $elapsed = microtime(true) - $start;

    echo "4. Job processado em "
        . round($elapsed, 2)
        . " segundos.\n";

    if ($elapsed < 5) {
        throw new RuntimeException(
            'Job finished earlier than expected.'
        );
    }

    $statement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM queue_jobs
         WHERE id = :id'
    );

    $statement->execute(['id' => $jobId]);

    if ((int) $statement->fetchColumn() !== 0) {
        throw new RuntimeException(
            'Completed job remains in the queue.'
        );
    }

    echo "5. Job removido corretamente da fila.\n";

    echo "\nTESTE DE HEARTBEAT AUTOMÁTICO CONCLUÍDO COM SUCESSO!\n";
} finally {
    unset($queue, $pdo);

    if (is_file($databaseFile)) {
        unlink($databaseFile);
    }
}

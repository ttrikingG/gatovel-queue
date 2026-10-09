<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Gatovel\Queue\drivers\DatabaseDriver;
use Gatovel\Queue\failed\FailedJobRepository;
use Gatovel\Queue\jobs\FailingJob;
use Gatovel\Queue\retry\RetryPolicy;
use Gatovel\Queue\workers\Worker;

$pdo = new PDO('sqlite::memory:');

$queue = new DatabaseDriver($pdo);
$queue->createTable();

$failedJobs = new FailedJobRepository($pdo);
$failedJobs->createTable();

$retryPolicy = new RetryPolicy(
    maxAttempts: 3,
    baseDelay: 1,
    maxDelay: 2
);

$worker = new Worker(
    $queue,
    $failedJobs,
    $retryPolicy
);

$jobId = $queue->push(
    FailingJob::class,
    ['email' => 'cliente@example.com']
);

echo "Job criado: {$jobId}\n";

for ($attempt = 1; $attempt <= 3; $attempt++) {
    echo "\nTentativa {$attempt}:\n";

    try {
        $worker->runOnce();
        throw new RuntimeException(
            'O Worker deveria ter lançado uma exceção.'
        );
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() !== 'Serviço de e-mail indisponível.') {
            throw $exception;
        }

        echo "Falha esperada: {$exception->getMessage()}\n";
    }

    if ($attempt < 3) {
        $delay = $retryPolicy->backoff($attempt);

        echo "Aguardando {$delay} segundo(s)...\n";
        sleep($delay);
    }
}

$failed = $failedJobs->find($jobId);

if ($failed === null) {
    throw new RuntimeException(
        'O Job deveria estar registrado em Failed Jobs.'
    );
}

if ((int) $failed['attempts'] !== 3) {
    throw new RuntimeException(
        'Número de tentativas incorreto.'
    );
}

$remaining = (int) $pdo->query(
    'SELECT COUNT(*) FROM queue_jobs'
)->fetchColumn();

if ($remaining !== 0) {
    throw new RuntimeException(
        'O Job ainda está na fila principal.'
    );
}

echo "\nJob registrado em Failed Jobs.\n";
echo "Tentativas: {$failed['attempts']}\n";
echo "Erro: {$failed['exception']}\n";

echo "\nTESTE DE RETRY CONCLUÍDO COM SUCESSO!\n";

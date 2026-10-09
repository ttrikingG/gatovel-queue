<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Gatovel\Queue\drivers\DatabaseDriver;
use Gatovel\Queue\failed\FailedJobRepository;

$pdo = new PDO('sqlite::memory:');

$queue = new DatabaseDriver(
    connection: $pdo,
    retryAfter: 2
);

$queue->createTable();

$failedJobs = new FailedJobRepository(
    connection: $pdo,
    retryAfter: 2
);

$failedJobs->createTable();

echo "1. Ambiente preparado com reserva de 2 segundos.\n";

$jobId = $queue->push(
    'ExampleJob',
    ['message' => 'Falha simulada']
);

$reserved = $queue->pop();

if ($reserved === null) {
    throw new RuntimeException(
        'Não foi possível reservar o Job.'
    );
}

$token = $reserved['reservation_token'];

echo "2. Job reservado pelo Worker A.\n";

// Simula a expiração sem utilizar sleep().
$statement = $pdo->prepare(
    'UPDATE queue_jobs
     SET reserved_at = :expired
     WHERE id = :id'
);

$statement->execute([
    'expired' => time() - 10,
    'id' => $jobId,
]);

echo "3. Reserva expirada artificialmente.\n";

$rejected = false;

try {
    $failedJobs->fail(
        $reserved,
        $token,
        new RuntimeException('Falha simulada.')
    );
} catch (RuntimeException $exception) {
    if (
        $exception->getMessage()
        !== 'Job reservation is no longer valid.'
    ) {
        throw $exception;
    }

    $rejected = true;
}

if (!$rejected) {
    throw new RuntimeException(
        'O repositório aceitou uma reserva expirada.'
    );
}

echo "4. Registro da falha expirado corretamente rejeitado.\n";

$failedCount = (int) $pdo->query(
    'SELECT COUNT(*) FROM queue_failed_jobs'
)->fetchColumn();

if ($failedCount !== 0) {
    throw new RuntimeException(
        'A tabela Failed Jobs deveria estar vazia.'
    );
}

echo "5. Nenhum registro indevido em Failed Jobs.\n";

$queueCount = (int) $pdo->query(
    'SELECT COUNT(*) FROM queue_jobs'
)->fetchColumn();

if ($queueCount !== 1) {
    throw new RuntimeException(
        'O Job deveria continuar na fila principal.'
    );
}

echo "6. Job original preservado.\n";

$newReservation = $queue->pop();

if ($newReservation === null) {
    throw new RuntimeException(
        'O Job não pôde ser reservado novamente.'
    );
}

if (
    $newReservation['id'] !== $jobId ||
    $newReservation['reservation_token'] === $token
) {
    throw new RuntimeException(
        'A nova reserva é inválida.'
    );
}

echo "7. Outro Worker pode reservar o Job.\n";

$failedJobs->fail(
    $newReservation,
    $newReservation['reservation_token'],
    new RuntimeException('Falha definitiva de teste.')
);

$failed = $failedJobs->find($jobId);

if ($failed === null) {
    throw new RuntimeException(
        'A falha da nova reserva não foi registrada.'
    );
}

if ((int) $failed['attempts'] !== 2) {
    throw new RuntimeException(
        'Número de tentativas incorreto.'
    );
}

echo "8. Nova reserva registrou a falha corretamente.\n";

$remaining = (int) $pdo->query(
    'SELECT COUNT(*) FROM queue_jobs'
)->fetchColumn();

if ($remaining !== 0) {
    throw new RuntimeException(
        'A fila principal deveria estar vazia.'
    );
}

echo "9. Job removido da fila principal.\n";

echo "\nTESTE DE EXPIRAÇÃO DE FAILED JOBS CONCLUÍDO COM SUCESSO!\n";

<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Gatovel\Queue\drivers\DatabaseDriver;

$pdo = new PDO('sqlite::memory:');

$queue = new DatabaseDriver(
    connection: $pdo,
    retryAfter: 2
);

$queue->createTable();

echo "1. Ambiente preparado com reserva de 2 segundos.\n";

$jobId = $queue->push(
    'ExampleJob',
    ['message' => 'Teste de expiração']
);

$reserved = $queue->pop();

if ($reserved === null) {
    throw new RuntimeException(
        'Não foi possível reservar o Job.'
    );
}

$token = $reserved['reservation_token'];

echo "2. Worker A reservou o Job.\n";

// Simula uma reserva expirada sem aguardar.
// Não existe outro Worker assumindo o Job.
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

if ($queue->delete($jobId, $token) !== false) {
    throw new RuntimeException(
        'delete() aceitou uma reserva expirada.'
    );
}

echo "4. delete() rejeitou a reserva expirada.\n";

if ($queue->release($jobId, $token) !== false) {
    throw new RuntimeException(
        'release() aceitou uma reserva expirada.'
    );
}

echo "5. release() rejeitou a reserva expirada.\n";

$remaining = (int) $pdo->query(
    'SELECT COUNT(*) FROM queue_jobs'
)->fetchColumn();

if ($remaining !== 1) {
    throw new RuntimeException(
        'O Job deveria continuar na fila.'
    );
}

echo "6. Job preservado na fila.\n";

$newReservation = $queue->pop();

if ($newReservation === null) {
    throw new RuntimeException(
        'O Job expirado não pôde ser reservado novamente.'
    );
}

if ($newReservation['id'] !== $jobId) {
    throw new RuntimeException(
        'Um Job diferente foi reservado.'
    );
}

if ($newReservation['reservation_token'] === $token) {
    throw new RuntimeException(
        'O token de reserva não foi renovado.'
    );
}

echo "7. Job reservado novamente com novo token.\n";

if (
    !$queue->delete(
        $jobId,
        $newReservation['reservation_token']
    )
) {
    throw new RuntimeException(
        'A nova reserva não conseguiu confirmar o Job.'
    );
}

echo "8. Nova reserva confirmou o Job com sucesso.\n";

echo "\nTESTE DE AÇÕES COM RESERVA EXPIRADA CONCLUÍDO!\n";

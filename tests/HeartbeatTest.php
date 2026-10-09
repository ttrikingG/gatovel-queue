<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Gatovel\Queue\drivers\DatabaseDriver;

$pdo = new PDO('sqlite::memory:');

$queue = new DatabaseDriver(
    connection: $pdo,
    retryAfter: 10
);

$queue->createTable();

echo "1. Ambiente preparado.\n";

$jobId = $queue->push(
    'ExampleJob',
    ['message' => 'Heartbeat']
);

$reserved = $queue->pop();

if ($reserved === null) {
    throw new RuntimeException(
        'Não foi possível reservar o Job.'
    );
}

$token = $reserved['reservation_token'];

echo "2. Worker A reservou o Job.\n";

// Simula uma reserva com 5 segundos de idade.
// O prazo total de reserva é de 10 segundos.
$statement = $pdo->prepare(
    'UPDATE queue_jobs
     SET reserved_at = :reserved_at
     WHERE id = :id'
);

$statement->execute([
    'reserved_at' => time() - 5,
    'id' => $jobId,
]);

if (!$queue->renew($jobId, $token)) {
    throw new RuntimeException(
        'Não foi possível renovar a reserva ativa.'
    );
}

echo "3. Reserva ativa renovada.\n";

$statement = $pdo->prepare(
    'SELECT reserved_at
     FROM queue_jobs
     WHERE id = :id'
);

$statement->execute(['id' => $jobId]);

$renewedAt = (int) $statement->fetchColumn();

if ($renewedAt < time() - 2) {
    throw new RuntimeException(
        'O horário da reserva não foi atualizado.'
    );
}

echo "4. Horário da reserva atualizado.\n";

if ($queue->renew($jobId, 'token-invalido')) {
    throw new RuntimeException(
        'Um token inválido conseguiu renovar a reserva.'
    );
}

echo "5. Token inválido rejeitado.\n";

// Agora a reserva está realmente expirada.
$statement = $pdo->prepare(
    'UPDATE queue_jobs
     SET reserved_at = :reserved_at
     WHERE id = :id'
);

$statement->execute([
    'reserved_at' => time() - 20,
    'id' => $jobId,
]);

if ($queue->renew($jobId, $token)) {
    throw new RuntimeException(
        'Uma reserva expirada foi renovada.'
    );
}

echo "6. Reserva expirada corretamente rejeitada.\n";

$newReservation = $queue->pop();

if ($newReservation === null) {
    throw new RuntimeException(
        'O Job expirado não pôde ser reservado novamente.'
    );
}

if ($newReservation['reservation_token'] === $token) {
    throw new RuntimeException(
        'O novo Worker recebeu o token antigo.'
    );
}

echo "7. Worker B assumiu o Job com novo token.\n";

// Simula novamente uma reserva ativa mais antiga.
$statement->execute([
    'reserved_at' => time() - 5,
    'id' => $jobId,
]);

if (
    !$queue->renew(
        $jobId,
        $newReservation['reservation_token']
    )
) {
    throw new RuntimeException(
        'Worker B não conseguiu renovar sua reserva.'
    );
}

echo "8. Worker B renovou a reserva.\n";

if ($queue->renew($jobId, $token)) {
    throw new RuntimeException(
        'Worker A conseguiu renovar usando o token antigo.'
    );
}

echo "9. Token antigo permanece inválido.\n";

if (
    !$queue->delete(
        $jobId,
        $newReservation['reservation_token']
    )
) {
    throw new RuntimeException(
        'Worker B não conseguiu confirmar o Job.'
    );
}

echo "10. Job confirmado pelo Worker B.\n";

echo "\nTESTE DE HEARTBEAT CONCLUÍDO COM SUCESSO!\n";

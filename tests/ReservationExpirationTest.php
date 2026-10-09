<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Gatovel\Queue\drivers\DatabaseDriver;

$pdo = new PDO('sqlite::memory:');

$queue = new DatabaseDriver($pdo);
$queue->createTable();

$jobId = $queue->push(
    'TestJob',
    ['message' => 'Teste de expiração']
);

echo "1. Job criado: {$jobId}\n";

$workerA = $queue->pop();

if ($workerA === null) {
    throw new RuntimeException(
        'Worker A não conseguiu reservar o Job.'
    );
}

$tokenA = $workerA['reservation_token'];

echo "2. Worker A reservou o Job.\n";

// Simula uma reserva expirada há mais de 60 segundos.
$statement = $pdo->prepare(
    'UPDATE queue_jobs
     SET reserved_at = :expired_at
     WHERE id = :id'
);

$statement->execute([
    'expired_at' => time() - 120,
    'id' => $jobId,
]);

echo "3. Reserva do Worker A expirada.\n";

$workerB = $queue->pop();

if ($workerB === null) {
    throw new RuntimeException(
        'Worker B não conseguiu recuperar o Job expirado.'
    );
}

$tokenB = $workerB['reservation_token'];

if ($tokenA === $tokenB) {
    throw new RuntimeException(
        'O token deveria mudar após uma nova reserva.'
    );
}

if ($workerB['attempts'] !== 2) {
    throw new RuntimeException(
        'Número de tentativas incorreto.'
    );
}

echo "4. Worker B reservou novamente o Job.\n";

if ($queue->delete($jobId, $tokenA)) {
    throw new RuntimeException(
        'Worker A removeu o Job com token antigo.'
    );
}

echo "5. Token antigo corretamente rejeitado.\n";

if (!$queue->delete($jobId, $tokenB)) {
    throw new RuntimeException(
        'Worker B não conseguiu remover o Job.'
    );
}

echo "6. Worker B confirmou o Job com sucesso.\n";

if ($queue->pop() !== null) {
    throw new RuntimeException(
        'A fila deveria estar vazia.'
    );
}

echo "\nTESTE DE EXPIRAÇÃO CONCLUÍDO COM SUCESSO!\n";

<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Gatovel\Queue\drivers\DatabaseDriver;

$pdo = new PDO('sqlite::memory:');

$queue = new DatabaseDriver($pdo);
$queue->createTable();

echo "1. Tabela criada com sucesso.\n";

$jobId = $queue->push(
    'SendWelcomeEmail',
    [
        'user_id' => 10,
        'email' => 'teste@example.com',
    ]
);

echo "2. Job criado: {$jobId}\n";

$job = $queue->pop();

if ($job === null) {
    throw new RuntimeException('Nenhum Job recuperado.');
}

if ($job['id'] !== $jobId) {
    throw new RuntimeException('ID incorreto.');
}

if ($job['attempts'] !== 1) {
    throw new RuntimeException('Tentativas incorretas.');
}

if ($job['payload']['user_id'] !== 10) {
    throw new RuntimeException('Payload incorreto.');
}

$token = $job['reservation_token'];

if (!is_string($token) || $token === '') {
    throw new RuntimeException('Token de reserva inválido.');
}

echo "3. Job reservado com token.\n";

if ($queue->delete($jobId, 'token-invalido')) {
    throw new RuntimeException(
        'Um token inválido conseguiu remover o Job.'
    );
}

echo "4. Token inválido corretamente rejeitado.\n";

if (!$queue->delete($jobId, $token)) {
    throw new RuntimeException(
        'Não foi possível remover o Job.'
    );
}

echo "5. Job removido com token válido.\n";

if ($queue->pop() !== null) {
    throw new RuntimeException('A fila deveria estar vazia.');
}

echo "6. Fila vazia confirmada.\n";

echo "\nTESTE CONCLUÍDO COM SUCESSO!\n";

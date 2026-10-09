<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Gatovel\Queue\drivers\DatabaseDriver;
use Gatovel\Queue\jobs\SendWelcomeEmail;
use Gatovel\Queue\workers\Worker;

$pdo = new PDO('sqlite::memory:');

$queue = new DatabaseDriver($pdo);
$queue->createTable();

echo "1. Banco SQLite preparado.\n";

$jobId = $queue->push(
    SendWelcomeEmail::class,
    [
        'user_id' => 10,
        'email' => 'cliente@example.com',
    ]
);

echo "2. Job adicionado à fila: {$jobId}\n";

$worker = new Worker($queue);

echo "3. Worker iniciando processamento...\n";

$processed = $worker->runOnce();

if (!$processed) {
    throw new RuntimeException(
        'O Worker não encontrou o Job.'
    );
}

echo "4. Worker finalizou o processamento.\n";

if ($queue->pop() !== null) {
    throw new RuntimeException(
        'O Job deveria ter sido removido da fila.'
    );
}

echo "5. Fila vazia confirmada.\n";

echo "\nTESTE DO WORKER CONCLUÍDO COM SUCESSO!\n";

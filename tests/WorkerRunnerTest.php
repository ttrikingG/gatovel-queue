<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Gatovel\Queue\drivers\DatabaseDriver;
use Gatovel\Queue\jobs\SendWelcomeEmail;
use Gatovel\Queue\workers\Worker;
use Gatovel\Queue\workers\WorkerRunner;

$pdo = new PDO('sqlite::memory:');

$queue = new DatabaseDriver($pdo);
$queue->createTable();

$emails = [
    'cliente1@example.com',
    'cliente2@example.com',
    'cliente3@example.com',
];

echo "1. Adicionando Jobs à fila...\n";

foreach ($emails as $email) {
    $jobId = $queue->push(
        SendWelcomeEmail::class,
        ['email' => $email]
    );

    echo "Job criado: {$jobId}\n";
}

echo "\n2. Iniciando WorkerRunner...\n\n";

$worker = new Worker($queue);

$runner = new WorkerRunner(
    $worker,
    sleepSeconds: 1
);

// Executa três ciclos de processamento.
$runner->run(
    queue: 'default',
    maxIterations: 3
);

echo "\n3. Verificando Jobs restantes...\n";

$remaining = (int) $pdo->query(
    'SELECT COUNT(*) FROM queue_jobs'
)->fetchColumn();

if ($remaining !== 0) {
    throw new RuntimeException(
        "Ainda existem {$remaining} Jobs na fila."
    );
}

echo "Todos os Jobs foram processados!\n";
echo "\nTESTE CONCLUÍDO COM SUCESSO!\n";

<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Gatovel\Queue\contracts\JobInterface;
use Gatovel\Queue\drivers\DatabaseDriver;
use Gatovel\Queue\exceptions\PermanentJobException;
use Gatovel\Queue\failed\FailedJobRepository;
use Gatovel\Queue\idempotency\IdempotencyStore;
use Gatovel\Queue\jobs\ProcessOrder;
use Gatovel\Queue\retry\RetryPolicy;
use Gatovel\Queue\workers\Worker;

$pdo = new PDO('sqlite::memory:');

$queue = new DatabaseDriver($pdo);
$queue->createTable();

$store = new IdempotencyStore($pdo);
$store->createTable();

$failedJobs = new FailedJobRepository($pdo);
$failedJobs->createTable();

$pdo->exec(
    'CREATE TABLE processed_orders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER NOT NULL UNIQUE,
        amount INTEGER NOT NULL
    )'
);

$jobFactory = function (string $className) use ($pdo): JobInterface {
    if ($className === ProcessOrder::class) {
        return new ProcessOrder($pdo);
    }

    throw new RuntimeException(
        "Job não registrado: {$className}"
    );
};

$worker = new Worker(
    queue: $queue,
    failedJobs: $failedJobs,
    retryPolicy: new RetryPolicy(maxAttempts: 5),
    jobFactory: $jobFactory
);

echo "1. Ambiente preparado com máximo de 5 tentativas.\n";

$queue->push(ProcessOrder::class, [
    'order_id' => 1001,
    'amount' => 15000,
]);

if (!$worker->runOnce()) {
    throw new RuntimeException(
        'O primeiro pedido não foi processado.'
    );
}

echo "2. Pedido original registrado.\n";

$conflictingJobId = $queue->push(
    ProcessOrder::class,
    [
        'order_id' => 1001,
        'amount' => 25000,
    ]
);

try {
    $worker->runOnce();

    throw new LogicException(
        'O Job conflitante deveria lançar uma exceção.'
    );
} catch (PermanentJobException $exception) {
    if (
        $exception->getMessage()
        !== 'Idempotency conflict for order 1001.'
    ) {
        throw $exception;
    }

    echo "3. PermanentJobException identificada.\n";
}

$statement = $pdo->query(
    'SELECT order_id, amount FROM processed_orders'
);

$orders = $statement->fetchAll(PDO::FETCH_ASSOC);

if (
    count($orders) !== 1 ||
    (int) $orders[0]['order_id'] !== 1001 ||
    (int) $orders[0]['amount'] !== 15000
) {
    throw new RuntimeException(
        'O pedido original foi alterado.'
    );
}

echo "4. Pedido original preservado.\n";

$failed = $failedJobs->find($conflictingJobId);

if ($failed === null) {
    throw new RuntimeException(
        'O Job conflitante não foi registrado em Failed Jobs.'
    );
}

if ((int) $failed['attempts'] !== 1) {
    throw new RuntimeException(
        'O Job permanente deveria falhar na primeira tentativa.'
    );
}

if (
    !str_contains(
        $failed['exception'],
        'Idempotency conflict for order 1001.'
    )
) {
    throw new RuntimeException(
        'O motivo da falha não foi registrado corretamente.'
    );
}

echo "5. Falha permanente registrada após 1 tentativa.\n";

$remaining = (int) $pdo->query(
    'SELECT COUNT(*) FROM queue_jobs'
)->fetchColumn();

if ($remaining !== 0) {
    throw new RuntimeException(
        'A fila principal deveria estar vazia.'
    );
}

echo "6. Fila principal vazia.\n";

if ($worker->runOnce() !== false) {
    throw new RuntimeException(
        'O Job permanente foi disponibilizado novamente.'
    );
}

echo "7. Nenhuma tentativa adicional realizada.\n";

echo "\nTESTE DE FALHA PERMANENTE CONCLUÍDO COM SUCESSO!\n";

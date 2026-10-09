<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Gatovel\Queue\contracts\JobInterface;
use Gatovel\Queue\drivers\DatabaseDriver;
use Gatovel\Queue\idempotency\IdempotencyStore;
use Gatovel\Queue\jobs\ProcessOrder;
use Gatovel\Queue\workers\Worker;

$pdo = new PDO('sqlite::memory:');

$queue = new DatabaseDriver($pdo);
$queue->createTable();

$store = new IdempotencyStore($pdo);
$store->createTable();

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
        "Job não registrado na fábrica: {$className}"
    );
};

$worker = new Worker(
    queue: $queue,
    jobFactory: $jobFactory
);

$payload = [
    'order_id' => 1001,
    'amount' => 15000,
];

echo "1. Ambiente preparado.\n";

$queue->push(ProcessOrder::class, $payload);
$queue->push(ProcessOrder::class, $payload);

echo "2. Dois Jobs do mesmo pedido adicionados.\n";

if (!$worker->runOnce()) {
    throw new RuntimeException(
        'O primeiro Job não foi processado.'
    );
}

if (!$worker->runOnce()) {
    throw new RuntimeException(
        'O segundo Job não foi processado.'
    );
}

echo "3. Ambos os Jobs executados pelo Worker.\n";

$total = (int) $pdo->query(
    'SELECT COUNT(*) FROM processed_orders'
)->fetchColumn();

if ($total !== 1) {
    throw new RuntimeException(
        "Esperado 1 pedido, encontrado: {$total}."
    );
}

echo "4. Apenas um pedido registrado.\n";

$order = $pdo->query(
    'SELECT order_id, amount FROM processed_orders'
)->fetch(PDO::FETCH_ASSOC);

if (
    $order === false ||
    (int) $order['order_id'] !== 1001 ||
    (int) $order['amount'] !== 15000
) {
    throw new RuntimeException(
        'Dados do pedido incorretos.'
    );
}

if (!$store->isCompleted('process-order-1001')) {
    throw new RuntimeException(
        'Chave de idempotência não registrada.'
    );
}

if ($queue->pop() !== null) {
    throw new RuntimeException(
        'A fila deveria estar vazia.'
    );
}

echo "5. Chave de idempotência confirmada.\n";
echo "6. Fila vazia confirmada.\n";

echo "\nTESTE DO PROCESS ORDER CONCLUÍDO COM SUCESSO!\n";

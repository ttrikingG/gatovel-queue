<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Gatovel\Queue\idempotency\IdempotencyStore;

$pdo = new PDO('sqlite::memory:');

$store = new IdempotencyStore($pdo);
$store->createTable();

$pdo->exec(
    'CREATE TABLE payments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER NOT NULL,
        amount INTEGER NOT NULL
    )'
);

echo "1. Banco de pagamentos preparado.\n";

$key = 'payment-order-1001';

$operation = function (PDO $connection): void {
    $statement = $connection->prepare(
        'INSERT INTO payments (order_id, amount)
         VALUES (:order_id, :amount)'
    );

    $statement->execute([
        'order_id' => 1001,
        'amount' => 15000,
    ]);
};

$executed = $store->executeOnce($key, $operation);

if (!$executed) {
    throw new RuntimeException(
        'A primeira operação deveria ser executada.'
    );
}

echo "2. Primeiro pagamento registrado.\n";

$executedAgain = $store->executeOnce($key, $operation);

if ($executedAgain) {
    throw new RuntimeException(
        'O pagamento foi executado novamente.'
    );
}

$total = (int) $pdo->query(
    'SELECT COUNT(*) FROM payments'
)->fetchColumn();

if ($total !== 1) {
    throw new RuntimeException(
        'O pagamento foi duplicado.'
    );
}

echo "3. Pagamento duplicado corretamente evitado.\n";

$failedKey = 'payment-order-1002';

try {
    $store->executeOnce(
        $failedKey,
        function (PDO $connection): void {
            $connection->exec(
                'INSERT INTO payments (order_id, amount)
                 VALUES (1002, 25000)'
            );

            throw new RuntimeException(
                'Falha simulada durante o pagamento.'
            );
        }
    );

    throw new LogicException(
        'A operação deveria ter falhado.'
    );
} catch (RuntimeException $exception) {
    if (
        $exception->getMessage()
        !== 'Falha simulada durante o pagamento.'
    ) {
        throw $exception;
    }

    echo "4. Falha simulada identificada.\n";
}

$total = (int) $pdo->query(
    'SELECT COUNT(*) FROM payments'
)->fetchColumn();

if ($total !== 1) {
    throw new RuntimeException(
        'O rollback não desfez o pagamento.'
    );
}

if ($store->isCompleted($failedKey)) {
    throw new RuntimeException(
        'A operação com falha foi marcada como concluída.'
    );
}

echo "5. Rollback confirmado.\n";
echo "6. Chave da operação com falha não registrada.\n";

$retried = $store->executeOnce(
    $failedKey,
    function (PDO $connection): void {
        $connection->exec(
            'INSERT INTO payments (order_id, amount)
             VALUES (1002, 25000)'
        );
    }
);

if (!$retried) {
    throw new RuntimeException(
        'A operação deveria permitir uma nova tentativa.'
    );
}

$total = (int) $pdo->query(
    'SELECT COUNT(*) FROM payments'
)->fetchColumn();

if ($total !== 2 || !$store->isCompleted($failedKey)) {
    throw new RuntimeException(
        'A recuperação após rollback falhou.'
    );
}

echo "7. Nova tentativa executada com sucesso.\n";

echo "\nTESTE TRANSACIONAL CONCLUÍDO COM SUCESSO!\n";

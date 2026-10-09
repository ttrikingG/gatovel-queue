<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Gatovel\Queue\idempotency\IdempotencyStore;

$pdo = new PDO('sqlite::memory:');

$store = new IdempotencyStore($pdo);
$store->createTable();

echo "1. Tabela de idempotência criada.\n";

$key = 'payment-order-1001';

if ($store->isCompleted($key)) {
    throw new RuntimeException(
        'A operação não deveria estar concluída.'
    );
}

echo "2. Chave ainda não registrada.\n";

if (!$store->markCompleted($key)) {
    throw new RuntimeException(
        'Não foi possível registrar a chave.'
    );
}

echo "3. Primeira execução registrada.\n";

if (!$store->isCompleted($key)) {
    throw new RuntimeException(
        'A operação deveria estar concluída.'
    );
}

if ($store->markCompleted($key)) {
    throw new RuntimeException(
        'Uma chave duplicada foi aceita.'
    );
}

echo "4. Chave duplicada corretamente rejeitada.\n";

if (!$store->markCompleted('payment-order-1002')) {
    throw new RuntimeException(
        'Uma chave diferente deveria ser aceita.'
    );
}

echo "5. Segunda chave registrada com sucesso.\n";

$total = (int) $pdo->query(
    'SELECT COUNT(*) FROM queue_idempotency'
)->fetchColumn();

if ($total !== 2) {
    throw new RuntimeException(
        'Quantidade incorreta de chaves registradas.'
    );
}

echo "6. Total de chaves confirmado: {$total}.\n";

echo "\nTESTE DE IDEMPOTÊNCIA CONCLUÍDO COM SUCESSO!\n";

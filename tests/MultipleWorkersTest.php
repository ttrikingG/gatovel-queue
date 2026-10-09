<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Gatovel\Queue\drivers\DatabaseDriver;

$databasePath = tempnam(
    sys_get_temp_dir(),
    'gatovel_queue_'
);

if ($databasePath === false) {
    throw new RuntimeException(
        'Não foi possível criar o banco temporário.'
    );
}

try {
    $pdoA = new PDO('sqlite:' . $databasePath);
    $pdoB = new PDO('sqlite:' . $databasePath);

    $queueA = new DatabaseDriver($pdoA);
    $queueB = new DatabaseDriver($pdoB);

    $queueA->createTable();

    $jobId = $queueA->push(
        'TestJob',
        ['message' => 'Teste de múltiplos Workers']
    );

    echo "1. Job criado: {$jobId}\n";

    $jobA = $queueA->pop();

    if ($jobA === null) {
        throw new RuntimeException(
            'Worker A não conseguiu reservar o Job.'
        );
    }

    echo "2. Worker A reservou o Job.\n";

    $jobB = $queueB->pop();

    if ($jobB !== null) {
        throw new RuntimeException(
            'Worker B reservou um Job já reservado.'
        );
    }

    echo "3. Worker B não recebeu o Job reservado.\n";

    if (!$queueA->delete(
        $jobId,
        $jobA['reservation_token']
    )) {
        throw new RuntimeException(
            'Worker A não conseguiu confirmar o Job.'
        );
    }

    echo "4. Worker A confirmou o processamento.\n";

    if ($queueB->pop() !== null) {
        throw new RuntimeException(
            'A fila deveria estar vazia.'
        );
    }

    echo "5. Worker B confirmou que a fila está vazia.\n";

    echo "\nTESTE DE MÚLTIPLOS WORKERS CONCLUÍDO COM SUCESSO!\n";
} finally {
    unset($queueA, $queueB, $pdoA, $pdoB);

    @unlink($databasePath);
}

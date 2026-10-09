<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Gatovel\Queue\executors\ProcessJobExecutor;
use Gatovel\Queue\exceptions\JobTimeoutException;
use Gatovel\Queue\jobs\SlowJob;

$executor = new ProcessJobExecutor(
    timeoutSeconds: 2
);

echo "1. Job configurado para executar por 10 segundos.\n";
echo "2. Timeout configurado para 2 segundos.\n";
echo "3. Iniciando execução...\n";

$start = hrtime(true);
$timeoutDetected = false;

try {
    $executor->execute([
        'id' => 'timeout-test',
        'reservation_token' => 'test-token',
        'job' => SlowJob::class,
        'payload' => [
            'seconds' => 10,
        ],
    ]);
} catch (JobTimeoutException $exception) {
    $timeoutDetected = true;

    echo "4. Timeout detectado: "
        . $exception->getMessage()
        . "\n";
}

$elapsed = (hrtime(true) - $start) / 1_000_000_000;

if (!$timeoutDetected) {
    throw new RuntimeException(
        'JobTimeoutException was not thrown.'
    );
}

if ($elapsed >= 8) {
    throw new RuntimeException(
        'Job was not interrupted promptly.'
    );
}

echo "5. Tempo total: "
    . round($elapsed, 2)
    . " segundos.\n";

echo "6. Executor retornou antes dos 10 segundos.\n";

echo "\nTESTE DE JOB TIMEOUT CONCLUÍDO COM SUCESSO!\n";

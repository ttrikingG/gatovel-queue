<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Gatovel\Queue\executors\ProcessJobExecutor;
use Gatovel\Queue\exceptions\JobTimeoutException;
use Gatovel\Queue\jobs\ProcessTrackingJob;

$pidFile = tempnam(sys_get_temp_dir(), 'gatovel-timeout-pid-');

if ($pidFile === false) {
    throw new RuntimeException(
        'Unable to create temporary PID file.'
    );
}

try {
    $executor = new ProcessJobExecutor(
        timeoutSeconds: 2
    );

    echo "1. Iniciando Job com duração de 10 segundos.\n";
    echo "2. Timeout configurado para 2 segundos.\n";

    $start = hrtime(true);
    $timeoutDetected = false;

    try {
        $executor->execute([
            'id' => 'timeout-termination-test',
            'reservation_token' => 'test-token',
            'job' => ProcessTrackingJob::class,
            'payload' => [
                'pid_file' => $pidFile,
            ],
        ]);
    } catch (JobTimeoutException $exception) {
        $timeoutDetected = true;

        echo "3. Timeout detectado corretamente.\n";
    }

    $elapsed = (hrtime(true) - $start) / 1_000_000_000;

    if (!$timeoutDetected) {
        throw new RuntimeException(
            'JobTimeoutException was not thrown.'
        );
    }

    $pid = trim((string) file_get_contents($pidFile));

    if ($pid === '' || !ctype_digit($pid)) {
        throw new RuntimeException(
            'Child process PID was not recorded.'
        );
    }

    echo "4. PID do processo filho: {$pid}\n";

    if (!is_dir('/proc')) {
        throw new RuntimeException(
            'This test requires Linux procfs.'
        );
    }

    if (is_dir('/proc/' . $pid)) {
        throw new RuntimeException(
            'Child process is still present after timeout.'
        );
    }

    echo "5. Processo filho não está mais presente.\n";

    if ($elapsed >= 8) {
        throw new RuntimeException(
            'Process termination took too long.'
        );
    }

    echo "6. Tempo total: "
        . round($elapsed, 2)
        . " segundos.\n";

    echo "\nTESTE DE ENCERRAMENTO POR TIMEOUT CONCLUÍDO COM SUCESSO!\n";
} finally {
    if (is_file($pidFile)) {
        unlink($pidFile);
    }
}

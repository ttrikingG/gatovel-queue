<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Gatovel\Queue\contracts\ReservationRenewalInterface;
use Gatovel\Queue\executors\ProcessJobExecutor;
use Gatovel\Queue\jobs\ProcessTrackingJob;

$pidFile = tempnam(sys_get_temp_dir(), 'gatovel-pid-');

if ($pidFile === false) {
    throw new RuntimeException('Unable to create PID file.');
}

$renewal = new class implements ReservationRenewalInterface {
    public function renew(
        string $jobId,
        string $reservationToken
    ): bool {
        return false;
    }
};

try {
    $executor = new ProcessJobExecutor(
        renewal: $renewal,
        heartbeatSeconds: 1
    );

    echo "1. Iniciando processo filho.\n";

    $failureDetected = false;

    try {
        $executor->execute([
            'id' => 'termination-test',
            'reservation_token' => 'test-token',
            'job' => ProcessTrackingJob::class,
            'payload' => [
                'pid_file' => $pidFile,
            ],
        ]);
    } catch (RuntimeException $exception) {
        if (!str_contains(
            $exception->getMessage(),
            'Unable to renew job reservation.'
        )) {
            throw $exception;
        }

        $failureDetected = true;
    }

    if (!$failureDetected) {
        throw new RuntimeException(
            'Heartbeat failure was not detected.'
        );
    }

    echo "2. Falha de heartbeat detectada.\n";

    $pid = trim((string) file_get_contents($pidFile));

    if ($pid === '' || !ctype_digit($pid)) {
        throw new RuntimeException(
            'Process PID was not recorded.'
        );
    }

    echo "3. PID do processo filho: {$pid}\n";

    // Linux: /proc/<pid> existe enquanto o processo está ativo
    // ou ainda não foi completamente recolhido pelo sistema.
    if (is_dir('/proc/' . $pid)) {
        throw new RuntimeException(
            'Child process is still present after termination.'
        );
    }

    echo "4. Processo filho não está mais presente.\n";

    echo "\nTESTE DE ENCERRAMENTO CONCLUÍDO COM SUCESSO!\n";
} finally {
    if (is_file($pidFile)) {
        unlink($pidFile);
    }
}

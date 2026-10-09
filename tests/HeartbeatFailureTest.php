<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Gatovel\Queue\contracts\ReservationRenewalInterface;
use Gatovel\Queue\executors\ProcessJobExecutor;
use Gatovel\Queue\jobs\SlowJob;

$renewal = new class implements ReservationRenewalInterface {
    public int $attempts = 0;

    public function renew(
        string $jobId,
        string $reservationToken
    ): bool {
        $this->attempts++;

        echo "Heartbeat {$this->attempts}: falha simulada.\n";

        return false;
    }
};

$executor = new ProcessJobExecutor(
    renewal: $renewal,
    heartbeatSeconds: 1
);

echo "1. Iniciando Job com duração de 10 segundos.\n";

$start = microtime(true);
$failed = false;

try {
    $executor->execute([
        'id' => 'test-heartbeat-failure',
        'reservation_token' => 'test-token',
        'job' => SlowJob::class,
        'payload' => [
            'seconds' => 10,
        ],
    ]);
} catch (RuntimeException $exception) {
    if (
        !str_contains(
            $exception->getMessage(),
            'Unable to renew job reservation.'
        )
    ) {
        throw $exception;
    }

    $failed = true;

    echo "2. Falha de renovação detectada.\n";
}

$elapsed = microtime(true) - $start;

if (!$failed) {
    throw new RuntimeException(
        'Heartbeat failure was not detected.'
    );
}

if ($renewal->attempts < 1) {
    throw new RuntimeException(
        'Heartbeat was never attempted.'
    );
}

if ($elapsed >= 8) {
    throw new RuntimeException(
        'Process was not interrupted promptly.'
    );
}

echo "3. Tempo de execução: "
    . round($elapsed, 2)
    . " segundos.\n";

echo "4. Executor interrompeu a espera pelo Job.\n";

echo "\nTESTE DE FALHA DE HEARTBEAT CONCLUÍDO COM SUCESSO!\n";

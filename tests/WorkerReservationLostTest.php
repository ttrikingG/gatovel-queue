<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Gatovel\Queue\contracts\QueueInterface;
use Gatovel\Queue\contracts\JobExecutorInterface;
use Gatovel\Queue\exceptions\ReservationLostException;
use Gatovel\Queue\workers\Worker;

$queue = new class implements QueueInterface {
    public int $deleteCalls = 0;
    public int $releaseCalls = 0;
    public int $popCalls = 0;

    public function push(
        string $job,
        array $payload = [],
        int $delay = 0
    ): string {
        return 'test-job-id';
    }

    public function pop(string $queue = 'default'): ?array
    {
        $this->popCalls++;

        return [
            'id' => 'test-job-id',
            'queue' => $queue,
            'job' => 'ExampleJob',
            'payload' => [],
            'attempts' => 1,
            'reservation_token' => 'test-token',
        ];
    }

    public function delete(
        string $jobId,
        string $reservationToken
    ): bool {
        $this->deleteCalls++;

        return true;
    }

    public function release(
        string $jobId,
        string $reservationToken,
        int $delay = 0
    ): bool {
        $this->releaseCalls++;

        return true;
    }
};

$executor = new class implements JobExecutorInterface {
    public int $executeCalls = 0;

    public function execute(array $job): void
    {
        $this->executeCalls++;

        throw new ReservationLostException(
            'Unable to renew job reservation.'
        );
    }
};

$worker = new Worker(
    queue: $queue,
    executor: $executor
);

echo "1. Iniciando Worker com perda de reserva simulada.\n";

$exceptionDetected = false;

try {
    $worker->runOnce();
} catch (ReservationLostException $exception) {
    $exceptionDetected = true;

    echo "2. ReservationLostException capturada.\n";
}

if (!$exceptionDetected) {
    throw new RuntimeException(
        'ReservationLostException was not propagated.'
    );
}

if ($queue->popCalls !== 1) {
    throw new RuntimeException(
        'Expected exactly one pop call.'
    );
}

if ($executor->executeCalls !== 1) {
    throw new RuntimeException(
        'Expected exactly one execution.'
    );
}

if ($queue->releaseCalls !== 0) {
    throw new RuntimeException(
        'Worker attempted to release a lost reservation.'
    );
}

if ($queue->deleteCalls !== 0) {
    throw new RuntimeException(
        'Worker attempted to delete a lost reservation.'
    );
}

echo "3. Nenhuma chamada a release().\n";
echo "4. Nenhuma chamada a delete().\n";
echo "5. Exceção propagada corretamente.\n";

echo "\nTESTE DE PERDA DE RESERVA CONCLUÍDO COM SUCESSO!\n";

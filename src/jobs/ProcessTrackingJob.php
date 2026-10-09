<?php

declare(strict_types=1);

namespace Gatovel\Queue\jobs;

use Gatovel\Queue\contracts\JobInterface;
use RuntimeException;

final class ProcessTrackingJob implements JobInterface
{
    public function handle(array $payload): void
    {
        $pidFile = $payload['pid_file'] ?? null;

        if (!is_string($pidFile) || $pidFile === '') {
            throw new RuntimeException('Invalid PID file.');
        }

        $pid = getmypid();

        if ($pid === false) {
            throw new RuntimeException('Unable to obtain PID.');
        }

        if (file_put_contents($pidFile, (string) $pid) === false) {
            throw new RuntimeException('Unable to write PID file.');
        }

        echo "Processo filho iniciado. PID: {$pid}\n";

        sleep(10);

        echo "Processo filho finalizado normalmente.\n";
    }
}

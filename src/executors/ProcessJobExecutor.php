<?php

declare(strict_types=1);

namespace Gatovel\Queue\executors;

use Gatovel\Queue\contracts\JobExecutorInterface;
use Gatovel\Queue\contracts\ReservationRenewalInterface;
use Gatovel\Queue\exceptions\JobTimeoutException;
use Gatovel\Queue\exceptions\ReservationLostException;
use RuntimeException;

final class ProcessJobExecutor implements JobExecutorInterface
{
    public function __construct(
        private readonly string $phpBinary = PHP_BINARY,
        private readonly ?ReservationRenewalInterface $renewal = null,
        private readonly int $heartbeatSeconds = 5,
        private readonly int $timeoutSeconds = 0
    ) {
        if ($heartbeatSeconds < 1) {
            throw new RuntimeException(
                'Heartbeat interval must be at least one second.'
            );
        }

        if ($timeoutSeconds < 0) {
            throw new RuntimeException(
                'Job timeout cannot be negative.'
            );
        }

        if ($phpBinary === '') {
            throw new RuntimeException(
                'PHP binary path cannot be empty.'
            );
        }

        if (!function_exists('proc_open')) {
            throw new RuntimeException(
                'proc_open is not available.'
            );
        }
    }

    public function execute(array $job): void
    {
        $startedAt = microtime(true);

        $process = proc_open(
            [
                $this->phpBinary,
                __DIR__ . '/process_job.php',
            ],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes
        );

        if (!is_resource($process)) {
            throw new RuntimeException(
                'Unable to start job process.'
            );
        }

        $closed = false;

        try {
            $input = json_encode(
                [
                    'job' => $job['job'] ?? null,
                    'payload' => $job['payload'] ?? null,
                ],
                JSON_THROW_ON_ERROR
            );

            $written = fwrite($pipes[0], $input);

            if ($written === false || $written !== strlen($input)) {
                throw new RuntimeException(
                    'Unable to send job input.'
                );
            }

            fclose($pipes[0]);

            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            $stdout = '';
            $stderr = '';
            $exitCode = null;

            $lastHeartbeat = microtime(true);

            while (true) {
                $stdout .= stream_get_contents($pipes[1]) ?: '';
                $stderr .= stream_get_contents($pipes[2]) ?: '';

                $status = proc_get_status($process);

                if (!$status['running']) {
                    $exitCode = $status['exitcode'];
                    break;
                }

                $now = microtime(true);

                if (
                    $this->timeoutSeconds > 0
                    && $now - $startedAt >= $this->timeoutSeconds
                ) {
                    throw new JobTimeoutException(
                        'Job exceeded the maximum execution time of '
                        . $this->timeoutSeconds
                        . ' seconds.'
                    );
                }

                if (
                    $this->renewal !== null
                    && $now - $lastHeartbeat >= $this->heartbeatSeconds
                ) {
                    $jobId = $job['id'] ?? null;
                    $token = $job['reservation_token'] ?? null;

                    if (!is_string($jobId) || !is_string($token)) {
                        throw new RuntimeException(
                            'Missing job reservation information.'
                        );
                    }

                    if (!$this->renewal->renew($jobId, $token)) {
                        throw new ReservationLostException(
                            'Unable to renew job reservation.'
                        );
                    }

                    $lastHeartbeat = microtime(true);
                }

                usleep(100000);
            }

            $stdout .= stream_get_contents($pipes[1]) ?: '';
            $stderr .= stream_get_contents($pipes[2]) ?: '';

            fclose($pipes[1]);
            fclose($pipes[2]);

            $closeCode = proc_close($process);
            $closed = true;

            if ($exitCode === -1) {
                $exitCode = $closeCode;
            }

            if ($stdout !== '') {
                echo $stdout;
            }

            if ($exitCode !== 0) {
                throw new RuntimeException(
                    'Job process failed: '
                    . trim($stderr ?: 'Unknown error.')
                );
            }
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }

            if (!$closed && is_resource($process)) {
                $this->terminateProcess($process);
            }
        }
    }

    private function terminateProcess($process): void
    {
        $status = proc_get_status($process);

        if ($status['running']) {
            proc_terminate($process);

            $deadline = microtime(true) + 2;

            while (microtime(true) < $deadline) {
                $status = proc_get_status($process);

                if (!$status['running']) {
                    break;
                }

                usleep(100000);
            }

            $status = proc_get_status($process);

            if ($status['running']) {
                proc_terminate($process, 9);
            }
        }

        proc_close($process);
    }
}

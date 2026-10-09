<?php

declare(strict_types=1);

namespace Gatovel\Queue\workers;

use InvalidArgumentException;
use Throwable;

class WorkerRunner
{
    public function __construct(
        private readonly Worker $worker,
        private readonly int $sleepSeconds = 2
    ) {
        if ($sleepSeconds < 1) {
            throw new InvalidArgumentException(
                'Sleep interval must be at least one second.'
            );
        }
    }

    public function run(
        string $queue = 'default',
        ?int $maxIterations = null
    ): void {
        if ($maxIterations !== null && $maxIterations < 1) {
            throw new InvalidArgumentException(
                'Max iterations must be greater than zero.'
            );
        }

        $iterations = 0;

        while (
            $maxIterations === null
            || $iterations < $maxIterations
        ) {
            $iterations++;

            try {
                $processed = $this->worker->runOnce($queue);

                if (!$processed) {
                    sleep($this->sleepSeconds);
                }
            } catch (Throwable $exception) {
                fwrite(
                    STDERR,
                    '[Queue Worker] ' . $exception->getMessage() . PHP_EOL
                );

                sleep($this->sleepSeconds);
            }
        }
    }
}

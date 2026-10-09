<?php

declare(strict_types=1);

namespace Gatovel\Queue\workers;

use Closure;
use Gatovel\Queue\contracts\JobExecutorInterface;
use Gatovel\Queue\contracts\QueueInterface;
use Gatovel\Queue\exceptions\PermanentJobException;
use Gatovel\Queue\exceptions\ReservationLostException;
use Gatovel\Queue\executors\SyncJobExecutor;
use Gatovel\Queue\failed\FailedJobRepository;
use Gatovel\Queue\retry\RetryPolicy;
use RuntimeException;
use Throwable;

class Worker
{
    private readonly RetryPolicy $retryPolicy;

    private readonly JobExecutorInterface $executor;

    public function __construct(
        private readonly QueueInterface $queue,
        private readonly ?FailedJobRepository $failedJobs = null,
        ?RetryPolicy $retryPolicy = null,
        ?Closure $jobFactory = null,
        ?JobExecutorInterface $executor = null
    ) {
        $this->retryPolicy = $retryPolicy ?? new RetryPolicy();

        $this->executor = $executor
            ?? new SyncJobExecutor($jobFactory);
    }

    public function runOnce(string $queueName = 'default'): bool
    {
        $reservedJob = $this->queue->pop($queueName);

        if ($reservedJob === null) {
            return false;
        }

        $jobId = $reservedJob['id'];
        $token = $reservedJob['reservation_token'];
        $attempts = (int) $reservedJob['attempts'];

        try {
            $this->executor->execute($reservedJob);
        } catch (ReservationLostException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->handleFailure(
                $reservedJob,
                $token,
                $attempts,
                $exception
            );

            throw $exception;
        }

        if (!$this->queue->delete($jobId, $token)) {
            throw new RuntimeException(
                'Unable to acknowledge completed job.'
            );
        }

        return true;
    }

    private function handleFailure(
        array $reservedJob,
        string $token,
        int $attempts,
        Throwable $exception
    ): void {
        $jobId = $reservedJob['id'];

        if ($exception instanceof PermanentJobException) {
            $this->failPermanently(
                $reservedJob,
                $token,
                $exception
            );

            return;
        }

        if ($this->retryPolicy->shouldRetry($attempts)) {
            $delay = $this->retryPolicy->backoff($attempts);

            if (!$this->queue->release($jobId, $token, $delay)) {
                throw new RuntimeException(
                    'Unable to release failed job.',
                    previous: $exception
                );
            }

            return;
        }

        $this->failPermanently(
            $reservedJob,
            $token,
            $exception
        );
    }

    private function failPermanently(
        array $reservedJob,
        string $token,
        Throwable $exception
    ): void {
        if ($this->failedJobs === null) {
            throw new RuntimeException(
                'FailedJobRepository is required for permanent failures.',
                previous: $exception
            );
        }

        $this->failedJobs->fail(
            $reservedJob,
            $token,
            $exception
        );
    }
}

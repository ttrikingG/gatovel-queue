<?php

declare(strict_types=1);

namespace Gatovel\Queue\executors;

use Closure;
use Gatovel\Queue\contracts\JobExecutorInterface;
use Gatovel\Queue\contracts\JobInterface;
use RuntimeException;

final class SyncJobExecutor implements JobExecutorInterface
{
    public function __construct(
        private readonly ?Closure $jobFactory = null
    ) {
    }

    public function execute(array $job): void
    {
        $className = $job['job'] ?? null;

        if (!is_string($className) || $className === '') {
            throw new RuntimeException(
                'Invalid job class name.'
            );
        }

        $instance = $this->jobFactory !== null
            ? ($this->jobFactory)($className)
            : new $className();

        if (!$instance instanceof JobInterface) {
            throw new RuntimeException(
                'Job must implement JobInterface.'
            );
        }

        $payload = $job['payload'] ?? [];

        if (!is_array($payload)) {
            throw new RuntimeException(
                'Invalid job payload.'
            );
        }

        $instance->handle($payload);
    }
}

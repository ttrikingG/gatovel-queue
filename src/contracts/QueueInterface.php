<?php

declare(strict_types=1);

namespace Gatovel\Queue\contracts;

interface QueueInterface
{
    public function push(
        string $job,
        array $payload = [],
        int $delay = 0
    ): string;

    public function pop(
        string $queue = 'default'
    ): ?array;

    public function delete(
        string $jobId,
        string $reservationToken
    ): bool;

    public function release(
        string $jobId,
        string $reservationToken,
        int $delay = 0
    ): bool;
}

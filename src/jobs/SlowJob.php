<?php

declare(strict_types=1);

namespace Gatovel\Queue\jobs;

use Gatovel\Queue\contracts\JobInterface;
use InvalidArgumentException;

final class SlowJob implements JobInterface
{
    public function handle(array $payload): void
    {
        $seconds = $payload['seconds'] ?? 5;

        if (!is_int($seconds) || $seconds < 1 || $seconds > 30) {
            throw new InvalidArgumentException(
                'Execution time must be between 1 and 30 seconds.'
            );
        }

        echo "SlowJob iniciado.\n";

        sleep($seconds);

        echo "SlowJob finalizado após {$seconds} segundos.\n";
    }
}

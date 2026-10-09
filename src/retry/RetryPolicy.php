<?php

declare(strict_types=1);

namespace Gatovel\Queue\retry;

use InvalidArgumentException;

final class RetryPolicy
{
    public function __construct(
        private readonly int $maxAttempts = 3,
        private readonly int $baseDelay = 5,
        private readonly int $maxDelay = 300
    ) {
        if ($maxAttempts < 1) {
            throw new InvalidArgumentException(
                'Maximum attempts must be at least one.'
            );
        }

        if ($baseDelay < 1 || $maxDelay < $baseDelay) {
            throw new InvalidArgumentException(
                'Invalid retry delay configuration.'
            );
        }
    }

    public function shouldRetry(int $attempts): bool
    {
        return $attempts < $this->maxAttempts;
    }

    public function backoff(int $attempts): int
    {
        if ($attempts < 1) {
            throw new InvalidArgumentException(
                'Attempts must be at least one.'
            );
        }

        $delay = $this->baseDelay;

        for ($i = 1; $i < $attempts; $i++) {
            if ($delay >= $this->maxDelay) {
                return $this->maxDelay;
            }

            $delay = min($this->maxDelay, $delay * 2);
        }

        return $delay;
    }
}

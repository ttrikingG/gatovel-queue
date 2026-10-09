<?php

declare(strict_types=1);

namespace Gatovel\Queue\contracts;

interface JobInterface
{
    public function handle(array $payload): void;
}

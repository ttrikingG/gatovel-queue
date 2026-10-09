<?php

declare(strict_types=1);

namespace Gatovel\Queue\contracts;

interface JobExecutorInterface
{
    public function execute(array $job): void;
}

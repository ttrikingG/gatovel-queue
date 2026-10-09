<?php

declare(strict_types=1);

namespace Gatovel\Queue\jobs;

use Gatovel\Queue\contracts\JobInterface;
use RuntimeException;

class FailingJob implements JobInterface
{
    public function handle(array $payload): void
    {
        echo "Executando Job que simula uma falha...\n";

        throw new RuntimeException(
            'Serviço de e-mail indisponível.'
        );
    }
}

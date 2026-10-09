<?php

declare(strict_types=1);

namespace Gatovel\Queue\jobs;

use Gatovel\Queue\contracts\JobInterface;

class SendWelcomeEmail implements JobInterface
{
    public function handle(array $payload): void
    {
        $email = $payload['email'] ?? null;

        if (!is_string($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException(
                'A valid email address is required.'
            );
        }

        echo "Enviando e-mail para: {$email}\n";
        echo "E-mail processado com sucesso!\n";
    }
}

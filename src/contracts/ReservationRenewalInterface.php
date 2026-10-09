<?php

declare(strict_types=1);

namespace Gatovel\Queue\contracts;

interface ReservationRenewalInterface
{
    public function renew(
        string $jobId,
        string $reservationToken
    ): bool;
}

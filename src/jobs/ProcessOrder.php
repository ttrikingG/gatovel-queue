<?php

declare(strict_types=1);

namespace Gatovel\Queue\jobs;

use Gatovel\Queue\contracts\JobInterface;
use Gatovel\Queue\exceptions\PermanentJobException;
use Gatovel\Queue\idempotency\IdempotencyStore;
use InvalidArgumentException;
use PDO;

final class ProcessOrder implements JobInterface
{
    public function __construct(
        private readonly PDO $connection
    ) {
    }

    public function handle(array $payload): void
    {
        $orderId = $payload['order_id'] ?? null;
        $amount = $payload['amount'] ?? null;

        if (
            !is_int($orderId) ||
            $orderId < 1 ||
            !is_int($amount) ||
            $amount < 1
        ) {
            throw new InvalidArgumentException(
                'Invalid order data.'
            );
        }

        $store = new IdempotencyStore($this->connection);

        $executed = $store->executeOnce(
            'process-order-' . $orderId,
            function (PDO $connection) use ($orderId, $amount): void {
                $statement = $connection->prepare(
                    'INSERT INTO processed_orders (
                        order_id,
                        amount
                    ) VALUES (
                        :order_id,
                        :amount
                    )'
                );

                $statement->execute([
                    'order_id' => $orderId,
                    'amount' => $amount,
                ]);
            }
        );

        if ($executed) {
            echo "Pedido {$orderId} processado.\n";
            return;
        }

        $statement = $this->connection->prepare(
            'SELECT amount
             FROM processed_orders
             WHERE order_id = :order_id'
        );

        $statement->execute([
            'order_id' => $orderId,
        ]);

        $registeredAmount = $statement->fetchColumn();

        if ($registeredAmount === false) {
            throw new PermanentJobException(
                'Idempotency key exists, but order was not found.'
            );
        }

        if ((int) $registeredAmount !== $amount) {
            throw new PermanentJobException(
                "Idempotency conflict for order {$orderId}."
            );
        }

        echo "Pedido {$orderId} já processado. Ignorando duplicação.\n";
    }
}

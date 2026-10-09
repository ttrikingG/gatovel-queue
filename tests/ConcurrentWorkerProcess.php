<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Gatovel\Queue\drivers\DatabaseDriver;

if ($argc !== 3) {
    fwrite(STDERR, "Uso: php ConcurrentWorkerProcess.php <banco> <log>\n");
    exit(1);
}

$databasePath = $argv[1];
$logPath = $argv[2];

$pdo = new PDO('sqlite:' . $databasePath);
$queue = new DatabaseDriver($pdo);

while (true) {
    $job = $queue->pop();

    if ($job === null) {
        break;
    }

    $jobId = $job['id'];
    $token = $job['reservation_token'];

    // Simula trabalho executado pelo processo.
    usleep(20000);

    if (!$queue->delete($jobId, $token)) {
        fwrite(STDERR, "Falha ao confirmar Job: {$jobId}\n");
        exit(1);
    }

    $line = $jobId . PHP_EOL;

    if (file_put_contents(
        $logPath,
        $line,
        FILE_APPEND | LOCK_EX
    ) === false) {
        fwrite(STDERR, "Falha ao registrar Job: {$jobId}\n");
        exit(1);
    }
}

exit(0);

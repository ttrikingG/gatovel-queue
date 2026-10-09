<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Gatovel\Queue\drivers\DatabaseDriver;

$databasePath = tempnam(sys_get_temp_dir(), 'gatovel_db_');
$logPath = tempnam(sys_get_temp_dir(), 'gatovel_log_');

if ($databasePath === false || $logPath === false) {
    throw new RuntimeException('Falha ao criar arquivos temporários.');
}

$processes = [];

try {
    $pdo = new PDO('sqlite:' . $databasePath);
    $queue = new DatabaseDriver($pdo);
    $queue->createTable();

    $totalJobs = 20;
    $expectedIds = [];

    for ($i = 1; $i <= $totalJobs; $i++) {
        $expectedIds[] = $queue->push(
            'TestJob',
            ['number' => $i]
        );
    }

    echo "1. {$totalJobs} Jobs adicionados.\n";

    $script = __DIR__ . '/ConcurrentWorkerProcess.php';

    for ($i = 1; $i <= 4; $i++) {
        $command = [
            PHP_BINARY,
            $script,
            $databasePath,
            $logPath,
        ];

        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes
        );

        if (!is_resource($process)) {
            throw new RuntimeException(
                "Falha ao iniciar Worker {$i}."
            );
        }

        fclose($pipes[0]);

        $processes[] = [
            'process' => $process,
            'stdout' => $pipes[1],
            'stderr' => $pipes[2],
        ];
    }

    echo "2. Quatro processos iniciados.\n";

    $errors = [];

    foreach ($processes as $index => $entry) {
        $stdout = stream_get_contents($entry['stdout']);
        $stderr = stream_get_contents($entry['stderr']);

        fclose($entry['stdout']);
        fclose($entry['stderr']);

        $exitCode = proc_close($entry['process']);

        if ($exitCode !== 0) {
            $errors[] = "Worker " . ($index + 1)
                . " falhou: " . $stderr . $stdout;
        }
    }

    $processes = [];

    if ($errors !== []) {
        throw new RuntimeException(implode(PHP_EOL, $errors));
    }

    echo "3. Todos os processos finalizaram.\n";

    $processedIds = file(
        $logPath,
        FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
    );

    if ($processedIds === false) {
        throw new RuntimeException('Falha ao ler o log.');
    }

    if (count($processedIds) !== $totalJobs) {
        throw new RuntimeException(
            'Quantidade incorreta de Jobs processados: '
            . count($processedIds)
        );
    }

    if (count(array_unique($processedIds)) !== $totalJobs) {
        throw new RuntimeException(
            'Foram encontrados Jobs duplicados.'
        );
    }

    sort($processedIds);
    sort($expectedIds);

    if ($processedIds !== $expectedIds) {
        throw new RuntimeException(
            'Os Jobs processados não correspondem aos enviados.'
        );
    }

    $remaining = (int) $pdo->query(
        'SELECT COUNT(*) FROM queue_jobs'
    )->fetchColumn();

    if ($remaining !== 0) {
        throw new RuntimeException(
            "Ainda existem {$remaining} Jobs na fila."
        );
    }

    echo "4. Todos os 20 Jobs foram confirmados.\n";
    echo "5. Nenhuma duplicação encontrada.\n";
    echo "6. Fila vazia confirmada.\n";

    echo "\nTESTE DE CONCORRÊNCIA CONCLUÍDO COM SUCESSO!\n";
} finally {
    foreach ($processes as $entry) {
        foreach (['stdout', 'stderr'] as $stream) {
            if (is_resource($entry[$stream])) {
                fclose($entry[$stream]);
            }
        }

        if (is_resource($entry['process'])) {
            proc_terminate($entry['process']);
            proc_close($entry['process']);
        }
    }

    unset($queue, $pdo);

    @unlink($databasePath);
    @unlink($logPath);
}

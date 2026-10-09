<?php

declare(strict_types=1);

$tests = glob(__DIR__ . '/*Test.php');

if ($tests === false || $tests === []) {
    fwrite(STDERR, "Nenhum teste encontrado.\n");
    exit(1);
}

sort($tests);

$total = count($tests);
$passed = 0;
$failed = 0;
$failures = [];

echo "========================================\n";
echo "       GATOVEL QUEUE - TEST SUITE       \n";
echo "========================================\n";
echo "Total de testes: {$total}\n\n";

foreach ($tests as $test) {
    $name = basename($test);

    echo "Executando: {$name}\n";

    $command = [
        PHP_BINARY,
        $test,
    ];

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open(
        $command,
        $descriptors,
        $pipes,
        dirname(__DIR__)
    );

    if (!is_resource($process)) {
        $failed++;
        $failures[] = $name;

        echo "[FALHOU] Não foi possível iniciar.\n\n";
        continue;
    }

    fclose($pipes[0]);

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);

    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);

    if ($exitCode === 0) {
        $passed++;

        echo "[PASSOU] {$name}\n\n";
    } else {
        $failed++;
        $failures[] = $name;

        echo "[FALHOU] {$name}\n";

        if ($stdout !== false && $stdout !== '') {
            echo $stdout;
        }

        if ($stderr !== false && $stderr !== '') {
            echo $stderr;
        }

        echo "\n";
    }
}

echo "========================================\n";
echo "              RESULTADO                 \n";
echo "========================================\n";
echo "Total: {$total}\n";
echo "Aprovados: {$passed}\n";
echo "Reprovados: {$failed}\n";

if ($failures !== []) {
    echo "\nTestes reprovados:\n";

    foreach ($failures as $failure) {
        echo "- {$failure}\n";
    }
}

exit($failed === 0 ? 0 : 1);

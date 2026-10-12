<?php

declare(strict_types=1);

use Gatovel\Queue\contracts\JobInterface;

$autoloadCandidates = [
    dirname(__DIR__, 2) . '/vendor/autoload.php',
    dirname(__DIR__, 4) . '/autoload.php',
];

$autoloadFound = false;

foreach ($autoloadCandidates as $autoload) {
    if (is_file($autoload)) {
        require_once $autoload;
        $autoloadFound = true;
        break;
    }
}

if (!$autoloadFound) {
    fwrite(
        STDERR,
        'Unable to locate Composer autoload.php.' . PHP_EOL
    );

    exit(1);
}

try {
    $input = stream_get_contents(STDIN);

    if ($input === false || $input === '') {
        throw new RuntimeException('Missing job input.');
    }

    $data = json_decode(
        $input,
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    if (!is_array($data)) {
        throw new RuntimeException('Invalid job input.');
    }

    $className = $data['job'] ?? null;
    $payload = $data['payload'] ?? null;

    if (
        !is_string($className)
        || $className === ''
        || !class_exists($className)
    ) {
        throw new RuntimeException('Job class not found.');
    }

    if (!is_array($payload)) {
        throw new RuntimeException('Invalid job payload.');
    }

    $job = new $className();

    if (!$job instanceof JobInterface) {
        throw new RuntimeException(
            'Job must implement JobInterface.'
        );
    }

    $job->handle($payload);

    exit(0);
} catch (Throwable $exception) {
    fwrite(
        STDERR,
        get_class($exception)
        . ': '
        . $exception->getMessage()
        . PHP_EOL
    );

    exit(1);
}

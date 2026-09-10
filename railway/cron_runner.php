<?php

declare(strict_types=1);

date_default_timezone_set('Asia/Tehran');

$root = dirname(__DIR__);
$cronDirectory = $root . '/cronbot';
$dataDirectory = getenv('MIRZA_DATA_DIR') ?: '/data/mirzabot';
$lockDirectory = rtrim((string) $dataDirectory, '/') . '/locks';
if (!is_dir($lockDirectory)) {
    mkdir($lockDirectory, 0775, true);
}

$jobs = [
    'statusday' => static fn(DateTimeImmutable $now): bool => (int) $now->format('i') % 15 === 0,
    'croncard' => static fn(DateTimeImmutable $now): bool => true,
    'NoticationsService' => static fn(DateTimeImmutable $now): bool => true,
    'payment_expire' => static fn(DateTimeImmutable $now): bool => (int) $now->format('i') % 5 === 0,
    'sendmessage' => static fn(DateTimeImmutable $now): bool => true,
    'plisio' => static fn(DateTimeImmutable $now): bool => (int) $now->format('i') % 3 === 0,
    'activeconfig' => static fn(DateTimeImmutable $now): bool => true,
    'disableconfig' => static fn(DateTimeImmutable $now): bool => true,
    'iranpay1' => static fn(DateTimeImmutable $now): bool => true,
    'backupbot' => static fn(DateTimeImmutable $now): bool => (int) $now->format('i') === 0 && (int) $now->format('G') % 5 === 0,
    'gift' => static fn(DateTimeImmutable $now): bool => (int) $now->format('i') % 2 === 0,
    'expireagent' => static fn(DateTimeImmutable $now): bool => (int) $now->format('i') % 30 === 0,
    'on_hold' => static fn(DateTimeImmutable $now): bool => (int) $now->format('i') % 15 === 0,
    'configtest' => static fn(DateTimeImmutable $now): bool => (int) $now->format('i') % 2 === 0,
    'uptime_node' => static fn(DateTimeImmutable $now): bool => (int) $now->format('i') % 15 === 0,
    'uptime_panel' => static fn(DateTimeImmutable $now): bool => (int) $now->format('i') % 15 === 0,
    'lottery' => static fn(DateTimeImmutable $now): bool => true,
];

$running = [];
$lastMinute = '';
$stop = false;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$stop): void { $stop = true; });
    pcntl_signal(SIGINT, static function () use (&$stop): void { $stop = true; });
}

fwrite(STDOUT, "MirzaBot internal scheduler started.\n");

while (!$stop) {
    foreach ($running as $name => $item) {
        $status = proc_get_status($item['process']);
        if ($status['running']) {
            continue;
        }
        proc_close($item['process']);
        flock($item['lock'], LOCK_UN);
        fclose($item['lock']);
        unset($running[$name]);
    }

    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran'));
    $minuteKey = $now->format('Y-m-d H:i');
    $restoreLock = rtrim((string) $dataDirectory, '/') . '/restore.lock';

    if ($minuteKey !== $lastMinute && !is_file($restoreLock)) {
        $lastMinute = $minuteKey;
        foreach ($jobs as $name => $isDue) {
            if (!$isDue($now) || isset($running[$name])) {
                continue;
            }

            $script = $cronDirectory . '/' . $name . '.php';
            if (!is_file($script)) {
                error_log("Missing scheduled job: {$script}");
                continue;
            }

            $lock = fopen($lockDirectory . '/' . $name . '.lock', 'c');
            if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
                if (is_resource($lock)) {
                    fclose($lock);
                }
                continue;
            }

            $descriptors = [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', 'php://stdout', 'a'],
                2 => ['file', 'php://stderr', 'a'],
            ];
            $process = proc_open([PHP_BINARY, $script], $descriptors, $pipes, $cronDirectory);
            if (!is_resource($process)) {
                flock($lock, LOCK_UN);
                fclose($lock);
                error_log("Unable to start scheduled job: {$name}");
                continue;
            }
            $running[$name] = ['process' => $process, 'lock' => $lock];
        }
    }

    usleep(500000);
}

foreach ($running as $item) {
    proc_terminate($item['process'], SIGTERM);
    proc_close($item['process']);
    flock($item['lock'], LOCK_UN);
    fclose($item['lock']);
}

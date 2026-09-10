<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    require_once dirname(__DIR__) . '/config.php';
    $database = (int) $pdo->query('SELECT 1')->fetchColumn() === 1;
    $dataDirectory = rtrim((string) (getenv('MIRZA_DATA_DIR') ?: '/data/mirzabot'), '/');
    $storage = is_dir($dataDirectory) && is_writable($dataDirectory);
    $healthy = $database && $storage;
    http_response_code($healthy ? 200 : 503);
    echo json_encode([
        'status' => $healthy ? 'ok' : 'degraded',
        'database' => $database,
        'persistent_storage' => $storage,
    ]);
} catch (Throwable $e) {
    error_log('Health check failed: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['status' => 'error']);
}

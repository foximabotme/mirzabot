<?php

if (getenv('RAILWAY_ENVIRONMENT') !== false) {
    ini_set('log_errors', '1');
    ini_set('error_log', '/proc/self/fd/2');
}

/**
 * MirzaBot configuration.
 *
 * On Railway values are read from environment variables. The placeholder values
 * are retained so the upstream web installer can still prepare a VPS install.
 */
function mirzaConfigValue(array $names, string $fallback = ''): string
{
    foreach ($names as $name) {
        $value = getenv($name);
        if ($value !== false && trim((string) $value) !== '') {
            return trim((string) $value);
        }
    }

    return $fallback;
}

function mirzaInstalledValue(string $value): string
{
    return preg_match('/^\{[^}]+\}$/', $value) ? '' : $value;
}

function mirzaConfigurationFailure(string $logMessage, string $publicMessage): never
{
    error_log($logMessage);
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $publicMessage . PHP_EOL);
        exit(1);
    }

    http_response_code(503);
    die($publicMessage);
}

// This variable is used for slow control panels. Keep null for the default.
$request_exec_timeout = null;

$databaseUrl = mirzaConfigValue(['MYSQL_URL', 'DATABASE_URL']);
$databaseParts = $databaseUrl !== '' ? parse_url($databaseUrl) : false;

$dbhost = mirzaConfigValue(
    ['MYSQLHOST', 'DB_HOST'],
    is_array($databaseParts) ? urldecode((string) ($databaseParts['host'] ?? '')) : mirzaInstalledValue('{database_url}')
);
$dbport = mirzaConfigValue(
    ['MYSQLPORT', 'DB_PORT'],
    is_array($databaseParts) && isset($databaseParts['port']) ? (string) $databaseParts['port'] : '3306'
);
$dbname = mirzaConfigValue(
    ['MYSQLDATABASE', 'DB_NAME'],
    is_array($databaseParts) ? ltrim(urldecode((string) ($databaseParts['path'] ?? '')), '/') : mirzaInstalledValue('{database_name}')
);
$usernamedb = mirzaConfigValue(
    ['MYSQLUSER', 'DB_USER'],
    is_array($databaseParts) ? urldecode((string) ($databaseParts['user'] ?? '')) : mirzaInstalledValue('{username_db}')
);
$passworddb = mirzaConfigValue(
    ['MYSQLPASSWORD', 'DB_PASSWORD'],
    is_array($databaseParts) ? urldecode((string) ($databaseParts['pass'] ?? '')) : mirzaInstalledValue('{password_db}')
);

if ($dbhost === '' || $dbname === '' || $usernamedb === '') {
    mirzaConfigurationFailure(
        'Database configuration is incomplete. Set MYSQL_URL or the MYSQL* variables.',
        'error: database configuration is incomplete'
    );
}

$mysqlInitCommandAttribute = constant(
    defined('Pdo\\Mysql::ATTR_INIT_COMMAND')
        ? 'Pdo\\Mysql::ATTR_INIT_COMMAND'
        : 'PDO::MYSQL_ATTR_INIT_COMMAND'
);

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    $mysqlInitCommandAttribute => 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci',
];
$dsn = "mysql:host=$dbhost;port=$dbport;dbname=$dbname;charset=utf8mb4";

try {
    $pdo = new PDO($dsn, $usernamedb, $passworddb, $options);
} catch (PDOException $e) {
    mirzaConfigurationFailure('Database connection failed: ' . $e->getMessage(), 'error: database connection failed');
}

$APIKEY = mirzaConfigValue(['BOT_TOKEN', 'API_KEY'], mirzaInstalledValue('{API_KEY}'));
$adminnumber = mirzaConfigValue(['OWNER_ID', 'ADMIN_ID'], mirzaInstalledValue('{admin_number}'));
$domainhosts = mirzaConfigValue(['APP_DOMAIN', 'RAILWAY_PUBLIC_DOMAIN'], mirzaInstalledValue('{domain_name}'));
$domainhosts = preg_replace('#^https?://#i', '', $domainhosts);
$domainhosts = rtrim((string) $domainhosts, '/');

$usernamebot = mirzaConfigValue(['BOT_USERNAME'], mirzaInstalledValue('{username_bot}'));
if ($usernamebot === '') {
    $usernameCache = rtrim(mirzaConfigValue(['MIRZA_DATA_DIR'], '/data/mirzabot'), '/') . '/bot_username';
    if (is_file($usernameCache)) {
        $usernamebot = trim((string) file_get_contents($usernameCache));
    }
}
$usernamebot = ltrim($usernamebot, '@');

if ($APIKEY === '' || $adminnumber === '') {
    mirzaConfigurationFailure(
        'Bot configuration is incomplete. Set BOT_TOKEN and OWNER_ID.',
        'error: bot configuration is incomplete'
    );
}

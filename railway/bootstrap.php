<?php

declare(strict_types=1);

date_default_timezone_set('Asia/Tehran');

$root = dirname(__DIR__);
chdir($root);

require_once $root . '/config.php';
require_once $root . '/function.php';
require_once $root . '/botapi.php';
require_once __DIR__ . '/runtime.php';

if (getenv('RAILWAY_ENVIRONMENT') !== false && mirzaWebhookSecret() === '') {
    fwrite(STDERR, "TELEGRAM_WEBHOOK_SECRET is required on Railway.\n");
    exit(1);
}

require $root . '/db/bootstrap.php';

$dataDirectory = mirzaDataDirectory();
$detectedUsername = mirzaCacheMainBotUsername($APIKEY, $dataDirectory);
if ($detectedUsername === '') {
    fwrite(STDERR, "Warning: unable to detect the main bot username. Set BOT_USERNAME manually.\n");
}

if ($domainhosts === '') {
    fwrite(STDOUT, "Database is ready. Generate a Railway domain, then redeploy to register webhooks.\n");
    exit(0);
}

$webhooks = mirzaRegisterAllWebhooks($pdo, $domainhosts, $APIKEY);
if (!$webhooks['main']) {
    fwrite(STDERR, "Warning: Telegram rejected the main bot webhook. Check BOT_TOKEN and APP_DOMAIN.\n");
}

fwrite(
    STDOUT,
    sprintf(
        "MirzaBot bootstrap complete; main webhook=%s, agent webhooks=%d, failed=%d.\n",
        $webhooks['main'] ? 'ok' : 'failed',
        $webhooks['agents'],
        count($webhooks['failed'])
    )
);

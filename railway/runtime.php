<?php

declare(strict_types=1);

function mirzaCacheMainBotUsername(string $token, string $dataDirectory): string
{
    $cachedFile = rtrim($dataDirectory, '/') . '/bot_username';
    $response = telegram('getMe', [], $token);
    $username = is_array($response) ? (string) ($response['result']['username'] ?? '') : '';
    $username = ltrim(trim($username), '@');

    if ($username !== '' && preg_match('/^[A-Za-z0-9_]{1,64}$/', $username)) {
        if (!is_dir(dirname($cachedFile))) {
            mkdir(dirname($cachedFile), 0775, true);
        }
        file_put_contents($cachedFile, $username, LOCK_EX);
        return $username;
    }

    return is_file($cachedFile) ? trim((string) file_get_contents($cachedFile)) : '';
}

function mirzaRegisterAllWebhooks(PDO $pdo, string $domain, string $mainToken): array
{
    $result = ['main' => false, 'agents' => 0, 'failed' => []];
    $domain = rtrim(preg_replace('#^https?://#i', '', trim($domain)), '/');
    if ($domain === '') {
        return $result;
    }

    $mainResponse = telegram(
        'setWebhook',
        mirzaTelegramWebhookParameters("https://{$domain}/index.php"),
        $mainToken
    );
    $result['main'] = is_array($mainResponse) && !empty($mainResponse['ok']);
    if (!$result['main']) {
        $result['failed'][] = 'main';
    }

    try {
        $bots = $pdo->query('SELECT id_user, bot_token, username FROM botsaz')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('Unable to load agent bots for webhook registration: ' . $e->getMessage());
        return $result;
    }

    foreach ($bots as $bot) {
        $id = (string) ($bot['id_user'] ?? '');
        $token = (string) ($bot['bot_token'] ?? '');
        $username = (string) ($bot['username'] ?? '');
        if (!preg_match('/^\d+$/', $id) || !preg_match('/^[A-Za-z0-9_]{1,64}$/', $username) || $token === '') {
            $result['failed'][] = $username !== '' ? $username : $id;
            continue;
        }

        $response = telegram(
            'setWebhook',
            mirzaTelegramWebhookParameters("https://{$domain}/vpnbot/{$id}{$username}/index.php"),
            $token
        );
        if (is_array($response) && !empty($response['ok'])) {
            $result['agents']++;
        } else {
            $result['failed'][] = $username;
        }
    }

    return $result;
}

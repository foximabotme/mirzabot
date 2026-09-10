<?php

declare(strict_types=1);

$appRoot = rtrim((string) (getenv('MIRZA_APP_ROOT') ?: dirname(__DIR__)), '/');
$dataRoot = rtrim((string) (getenv('MIRZA_DATA_DIR') ?: '/data/mirzabot'), '/');

function mirzaRuntimeRemove(string $path): void
{
    if (is_link($path) || is_file($path)) {
        unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $item) {
        if ($item !== '.' && $item !== '..') {
            mirzaRuntimeRemove($path . '/' . $item);
        }
    }
    rmdir($path);
}

function mirzaRuntimeCopy(string $source, string $destination): void
{
    if (is_link($source)) {
        return;
    }
    if (is_file($source)) {
        if (!is_dir(dirname($destination))) {
            mkdir(dirname($destination), 0775, true);
        }
        if (!copy($source, $destination)) {
            throw new RuntimeException("Unable to copy {$source}");
        }
        return;
    }
    if (!is_dir($source)) {
        return;
    }
    if (!is_dir($destination)) {
        mkdir($destination, 0775, true);
    }
    foreach (scandir($source) ?: [] as $item) {
        if ($item !== '.' && $item !== '..') {
            mirzaRuntimeCopy($source . '/' . $item, $destination . '/' . $item);
        }
    }
}

function mirzaRuntimeLink(string $source, string $target, bool $seed = false): void
{
    if ($seed && !file_exists($target) && is_file($source)) {
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0775, true);
        }
        copy($source, $target);
    }
    if (is_link($source) || is_file($source) || is_dir($source)) {
        mirzaRuntimeRemove($source);
    }
    if (!symlink($target, $source)) {
        throw new RuntimeException("Unable to link {$source} to persistent storage");
    }
}

foreach ([$dataRoot, $dataRoot . '/vpnbot', $dataRoot . '/storage', $dataRoot . '/cronstate', $dataRoot . '/backups', $dataRoot . '/tmp'] as $directory) {
    if (!is_dir($directory)) {
        mkdir($directory, 0775, true);
    }
}

$sourceVpn = $appRoot . '/vpnbot';
$persistentVpn = $dataRoot . '/vpnbot';
foreach (['Default', 'update'] as $template) {
    mirzaRuntimeRemove($persistentVpn . '/' . $template);
    mirzaRuntimeCopy($sourceVpn . '/' . $template, $persistentVpn . '/' . $template);
}
if (is_file($sourceVpn . '/index.php')) {
    copy($sourceVpn . '/index.php', $persistentVpn . '/index.php');
}

// Bring restored/generated agent bots onto the current code without replacing
// their token, data, products, or customized text.
$updateSource = $sourceVpn . '/update';
foreach (scandir($persistentVpn) ?: [] as $agent) {
    $agentDirectory = $persistentVpn . '/' . $agent;
    if ($agent === '.' || $agent === '..' || in_array($agent, ['Default', 'update'], true) || !is_dir($agentDirectory)) {
        continue;
    }
    foreach (['admin.php', 'botapi.php', 'func.php', 'index.php', 'keyboard.php', 'version'] as $codeFile) {
        if (is_file($updateSource . '/' . $codeFile)) {
            copy($updateSource . '/' . $codeFile, $agentDirectory . '/' . $codeFile);
        }
    }
    $newText = json_decode((string) @file_get_contents($updateSource . '/text.json'), true);
    $oldText = json_decode((string) @file_get_contents($agentDirectory . '/text.json'), true);
    if (is_array($newText)) {
        $mergedText = is_array($oldText) ? array_replace_recursive($newText, $oldText) : $newText;
        file_put_contents(
            $agentDirectory . '/text.json',
            json_encode($mergedText, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            LOCK_EX
        );
    }
}

mirzaRuntimeLink($sourceVpn, $persistentVpn);
mirzaRuntimeLink($appRoot . '/storage', $dataRoot . '/storage');
mirzaRuntimeLink($appRoot . '/images.jpg', $dataRoot . '/images.jpg', true);
mirzaRuntimeLink($appRoot . '/custom.jpg', $dataRoot . '/custom.jpg', true);
mirzaRuntimeLink($appRoot . '/api/hash.txt', $dataRoot . '/api_hash.txt', true);

foreach (['users.json', 'info', 'gift', 'username.json'] as $stateFile) {
    mirzaRuntimeLink($appRoot . '/cronbot/' . $stateFile, $dataRoot . '/cronstate/' . $stateFile, true);
}

fwrite(STDOUT, "Persistent MirzaBot runtime prepared at {$dataRoot}.\n");

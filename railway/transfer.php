<?php

declare(strict_types=1);

date_default_timezone_set('Asia/Tehran');
ini_set('display_errors', '0');
set_time_limit(0);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");

$backupSecret = trim((string) (getenv('BACKUP_SECRET') ?: ''));
if (strlen($backupSecret) < 24) {
    http_response_code(503);
    echo 'BACKUP_SECRET is not configured or is shorter than 24 characters.';
    exit;
}

session_name('mirzabot_transfer');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/railway/transfer.php',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

$secretFingerprint = hash('sha256', $backupSecret);
if (isset($_POST['login_secret'])) {
    if (hash_equals($backupSecret, (string) $_POST['login_secret'])) {
        session_regenerate_id(true);
        $_SESSION['authenticated'] = $secretFingerprint;
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
    } else {
        usleep(500000);
        $_SESSION['login_error'] = 'رمز بک‌آپ درست نیست.';
    }
    header('Location: /railway/transfer.php', true, 303);
    exit;
}

if (isset($_POST['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: /railway/transfer.php', true, 303);
    exit;
}

$authenticated = isset($_SESSION['authenticated'])
    && is_string($_SESSION['authenticated'])
    && hash_equals($secretFingerprint, $_SESSION['authenticated']);
$message = '';
$error = '';

if ($authenticated && isset($_POST['action'])) {
    $csrf = (string) ($_POST['csrf'] ?? '');
    if (!isset($_SESSION['csrf']) || !hash_equals((string) $_SESSION['csrf'], $csrf)) {
        http_response_code(403);
        $error = 'درخواست منقضی شده است؛ صفحه را تازه کنید.';
    } else {
        $root = dirname(__DIR__);
        require_once $root . '/config.php';
        require_once $root . '/function.php';
        require_once $root . '/botapi.php';
        require_once __DIR__ . '/runtime.php';
        require_once __DIR__ . '/TransferManager.php';
        $manager = new MirzaTransferManager($pdo, $root, mirzaDataDirectory());

        try {
            if ($_POST['action'] === 'export') {
                $archive = $manager->createArchive();
                $downloadName = 'mirzabot-transfer-' . date('Y-m-d-His') . '.zip';
                session_write_close();
                header('Content-Type: application/zip');
                header('Content-Length: ' . filesize($archive));
                header('Content-Disposition: attachment; filename="' . $downloadName . '"');
                readfile($archive);
                @unlink($archive);
                exit;
            }

            if ($_POST['action'] === 'restore') {
                if ((string) ($_POST['confirmation'] ?? '') !== 'RESTORE') {
                    throw new RuntimeException('برای تأیید، عبارت RESTORE را دقیق وارد کنید.');
                }
                if (!isset($_FILES['backup']) || (int) $_FILES['backup']['error'] !== UPLOAD_ERR_OK) {
                    throw new RuntimeException('فایل ZIP کامل آپلود نشد.');
                }
                $uploaded = (string) $_FILES['backup']['tmp_name'];
                if (!is_uploaded_file($uploaded)) {
                    throw new RuntimeException('فایل آپلودشده معتبر نیست.');
                }

                $result = $manager->restoreArchive($uploaded);
                require $root . '/db/bootstrap.php';
                mirzaCacheMainBotUsername($APIKEY, mirzaDataDirectory());
                $webhooks = mirzaRegisterAllWebhooks($pdo, $domainhosts, $APIKEY);
                $message = sprintf(
                    'انتقال کامل شد. نسخه اضطراری %s ذخیره شد؛ وب‌هوک اصلی %s و %d ربات نمایندگی ثبت شد. حالا سرویس را Restart کنید.',
                    $result['pre_restore_backup'],
                    $webhooks['main'] ? 'ثبت شد' : 'ثبت نشد',
                    $webhooks['agents']
                );
            }
        } catch (Throwable $e) {
            error_log('MirzaBot transfer failed: ' . $e->getMessage());
            $error = 'عملیات انجام نشد: ' . $e->getMessage();
        }
    }
}

function transferEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$loginError = (string) ($_SESSION['login_error'] ?? '');
unset($_SESSION['login_error']);
$csrf = (string) ($_SESSION['csrf'] ?? '');
?><!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>انتقال MirzaBot</title>
    <style>
        *{box-sizing:border-box}body{margin:0;background:#07111f;color:#e8eef7;font-family:Tahoma,Arial,sans-serif;line-height:1.8}.wrap{max-width:780px;margin:48px auto;padding:20px}.card{background:#101d30;border:1px solid #24364f;border-radius:18px;padding:26px;margin-bottom:20px;box-shadow:0 14px 45px #0006}h1,h2{margin-top:0}p{color:#b9c7da}.notice{padding:12px 15px;border-radius:10px;margin:14px 0}.ok{background:#123d2b;color:#b9f6d2}.err{background:#4a1d28;color:#ffd0d8}label{display:block;margin:12px 0 5px}input[type=password],input[type=text],input[type=file]{width:100%;padding:12px;border:1px solid #40536f;border-radius:9px;background:#091526;color:#fff}button{border:0;border-radius:9px;padding:11px 18px;font-weight:bold;cursor:pointer;background:#2f7df4;color:white}.danger{background:#d1445a}.muted{background:#53657e}.row{display:flex;gap:10px;flex-wrap:wrap}.small{font-size:.9rem;color:#91a4bd}code{direction:ltr;display:inline-block;background:#07111f;padding:1px 7px;border-radius:5px}
    </style>
</head>
<body><main class="wrap">
    <section class="card">
        <h1>بک‌آپ و انتقال MirzaBot</h1>
        <p>این صفحه دیتابیس، ربات‌های نمایندگی و فایل‌های پایدار را در یک ZIP خروجی می‌گیرد و روی اکانت جدید برمی‌گرداند.</p>
        <?php if ($loginError !== ''): ?><div class="notice err"><?= transferEscape($loginError) ?></div><?php endif; ?>
        <?php if ($error !== ''): ?><div class="notice err"><?= transferEscape($error) ?></div><?php endif; ?>
        <?php if ($message !== ''): ?><div class="notice ok"><?= transferEscape($message) ?></div><?php endif; ?>
        <?php if (!$authenticated): ?>
            <form method="post">
                <label for="secret">رمز BACKUP_SECRET</label>
                <input id="secret" name="login_secret" type="password" autocomplete="current-password" required autofocus>
                <p><button type="submit">ورود امن</button></p>
            </form>
        <?php else: ?>
            <form method="post" class="row">
                <input type="hidden" name="csrf" value="<?= transferEscape($csrf) ?>">
                <input type="hidden" name="action" value="export">
                <button type="submit">دانلود بک‌آپ کامل</button>
            </form>
        <?php endif; ?>
    </section>

    <?php if ($authenticated): ?>
    <section class="card">
        <h2>ایمپورت در اکانت جدید</h2>
        <p>فایل ZIP را انتخاب کنید. اطلاعات فعلی جایگزین می‌شود، اما ابتدا یک بک‌آپ اضطراری روی Volume جدید ساخته خواهد شد.</p>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?= transferEscape($csrf) ?>">
            <input type="hidden" name="action" value="restore">
            <label for="backup">فایل بک‌آپ</label>
            <input id="backup" name="backup" type="file" accept=".zip,application/zip" required>
            <label for="confirmation">برای تأیید بنویسید <code>RESTORE</code></label>
            <input id="confirmation" name="confirmation" type="text" autocomplete="off" required>
            <p><button class="danger" type="submit">ایمپورت و جایگزینی اطلاعات</button></p>
        </form>
        <p class="small">هنگام ایمپورت، دریافت پیام‌های جدید و اجرای کارهای دوره‌ای موقتاً متوقف می‌شود. پس از موفقیت، سرویس را یک‌بار Restart کنید.</p>
    </section>
    <form method="post"><button class="muted" name="logout" value="1" type="submit">خروج</button></form>
    <?php endif; ?>
</main></body></html>

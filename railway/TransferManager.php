<?php

declare(strict_types=1);

final class MirzaTransferManager
{
    private const FORMAT = 'mirzabot-railway-transfer';
    private const VERSION = 1;
    private const MAX_UNCOMPRESSED_BYTES = 1073741824;

    public function __construct(
        private PDO $pdo,
        private string $appRoot,
        private string $dataRoot
    ) {
        $this->appRoot = rtrim($this->appRoot, '/');
        $this->dataRoot = rtrim($this->dataRoot, '/');
        foreach ([$this->dataRoot, $this->dataRoot . '/tmp', $this->dataRoot . '/backups'] as $directory) {
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new RuntimeException("Unable to create {$directory}");
            }
        }
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('The PHP zip extension is required for transfer backups.');
        }
    }

    public function createArchive(?string $target = null): string
    {
        $target ??= $this->dataRoot . '/tmp/mirzabot-transfer-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.zip';
        $sqlFile = $this->dataRoot . '/tmp/database-' . bin2hex(random_bytes(6)) . '.sql';
        $this->dumpDatabase($sqlFile);

        $zip = new ZipArchive();
        if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($sqlFile);
            throw new RuntimeException('Unable to create the transfer archive.');
        }

        try {
            $manifest = [
                'format' => self::FORMAT,
                'version' => self::VERSION,
                'created_at' => gmdate('c'),
                'database_sha256' => hash_file('sha256', $sqlFile),
                'app_version' => trim((string) @file_get_contents($this->appRoot . '/version')),
            ];
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            $zip->addFile($sqlFile, 'database.sql');

            $vpnRoot = $this->dataRoot . '/vpnbot';
            foreach (scandir($vpnRoot) ?: [] as $item) {
                if ($item === '.' || $item === '..' || in_array($item, ['Default', 'update', 'index.php'], true)) {
                    continue;
                }
                if (preg_match('/^\d+[A-Za-z0-9_]+$/', $item) && is_dir($vpnRoot . '/' . $item) && !is_link($vpnRoot . '/' . $item)) {
                    $this->addTree($zip, $vpnRoot . '/' . $item, 'runtime/vpnbot/' . $item);
                }
            }

            $this->addTree($zip, $this->dataRoot . '/storage', 'runtime/storage');
            $this->addTree($zip, $this->dataRoot . '/cronstate', 'runtime/cronstate');
            foreach (['images.jpg' => 'images.jpg', 'custom.jpg' => 'custom.jpg', 'api_hash.txt' => 'api_hash.txt', 'bot_username' => 'bot_username'] as $file => $archiveName) {
                if (is_file($this->dataRoot . '/' . $file) && !is_link($this->dataRoot . '/' . $file)) {
                    $zip->addFile($this->dataRoot . '/' . $file, 'runtime/' . $archiveName);
                }
            }
        } finally {
            $zip->close();
            @unlink($sqlFile);
        }

        if (!is_file($target) || filesize($target) === 0) {
            throw new RuntimeException('The transfer archive is empty.');
        }

        return $target;
    }

    public function restoreArchive(string $archive): array
    {
        $zip = new ZipArchive();
        if ($zip->open($archive) !== true) {
            throw new RuntimeException('The uploaded file is not a readable ZIP archive.');
        }

        $stage = $this->dataRoot . '/tmp/restore-' . bin2hex(random_bytes(6));
        if (!mkdir($stage, 0700, true) && !is_dir($stage)) {
            $zip->close();
            throw new RuntimeException('Unable to prepare the restore directory.');
        }

        $lockPath = $this->dataRoot . '/restore.lock';
        $lock = @fopen($lockPath, 'x');
        if ($lock === false) {
            $zip->close();
            $this->removeTree($stage);
            throw new RuntimeException('Another restore is already running.');
        }
        fwrite($lock, (string) getmypid());
        fclose($lock);

        $preRestore = '';
        try {
            $this->validateArchive($zip);
            $this->extractArchive($zip, $stage);
            $manifest = json_decode((string) file_get_contents($stage . '/manifest.json'), true);
            if (!is_array($manifest)
                || ($manifest['format'] ?? '') !== self::FORMAT
                || (int) ($manifest['version'] ?? 0) !== self::VERSION
            ) {
                throw new RuntimeException('This ZIP is not a supported MirzaBot transfer backup.');
            }
            $expectedHash = (string) ($manifest['database_sha256'] ?? '');
            if ($expectedHash === '' || !hash_equals($expectedHash, hash_file('sha256', $stage . '/database.sql'))) {
                throw new RuntimeException('The database backup checksum is invalid.');
            }

            $preRestore = $this->dataRoot . '/backups/pre-restore-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.zip';
            $this->createArchive($preRestore);

            $this->importDatabase($stage . '/database.sql');
            $this->restoreRuntime($stage . '/runtime');
            $this->pruneEmergencyBackups();
        } finally {
            $zip->close();
            $this->removeTree($stage);
            @unlink($lockPath);
        }

        return ['pre_restore_backup' => basename($preRestore)];
    }

    private function dumpDatabase(string $target): void
    {
        $handle = fopen($target, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Unable to create the database dump.');
        }

        try {
            fwrite($handle, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");
            $tables = $this->pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
            foreach ($tables as $table) {
                $rawName = (string) $table[0];
                $name = '`' . str_replace('`', '``', $rawName) . '`';
                $isView = isset($table[1]) && strtoupper((string) $table[1]) === 'VIEW';
                $create = $this->pdo->query('SHOW CREATE TABLE ' . $name)->fetch(PDO::FETCH_NUM);
                $createSql = (string) ($create[1] ?? '');
                if ($isView) {
                    $createSql = preg_replace('/\sDEFINER=`[^`]+`@`[^`]+`/i', '', $createSql);
                }
                fwrite($handle, 'DROP ' . ($isView ? 'VIEW' : 'TABLE') . ' IF EXISTS ' . $name . ";\n" . $createSql . ";\n\n");
                if ($isView) {
                    continue;
                }

                $statement = $this->pdo->query('SELECT * FROM ' . $name);
                $batch = [];
                $columns = [];
                while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
                    if ($columns === []) {
                        foreach (array_keys($row) as $column) {
                            $columns[] = '`' . str_replace('`', '``', (string) $column) . '`';
                        }
                    }
                    $cells = [];
                    foreach ($row as $cell) {
                        $cells[] = $cell === null ? 'NULL' : $this->pdo->quote((string) $cell);
                    }
                    $batch[] = '(' . implode(',', $cells) . ')';
                    if (count($batch) >= 100) {
                        $this->writeInsert($handle, $name, $columns, $batch);
                        $batch = [];
                    }
                }
                if ($batch !== []) {
                    $this->writeInsert($handle, $name, $columns, $batch);
                }
                fwrite($handle, "\n");
            }
            fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
        } finally {
            fclose($handle);
        }
    }

    private function writeInsert($handle, string $table, array $columns, array $values): void
    {
        fwrite($handle, 'INSERT INTO ' . $table . ' (' . implode(',', $columns) . ") VALUES\n" . implode(",\n", $values) . ";\n");
    }

    private function importDatabase(string $sqlFile): void
    {
        $sql = file_get_contents($sqlFile);
        if ($sql === false) {
            throw new RuntimeException('Unable to read database.sql.');
        }

        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach ($this->splitSqlStatements($sql) as $statement) {
                $this->pdo->exec($statement);
            }
        } finally {
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    private function splitSqlStatements(string $sql): Generator
    {
        $length = strlen($sql);
        $buffer = '';
        $quote = null;
        $escaped = false;
        $lineComment = false;
        $blockComment = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            if ($lineComment) {
                if ($char === "\n") {
                    $lineComment = false;
                    $buffer .= $char;
                }
                continue;
            }
            if ($blockComment) {
                if ($char === '*' && $next === '/') {
                    $blockComment = false;
                    $i++;
                }
                continue;
            }
            if ($quote !== null) {
                $buffer .= $char;
                if ($escaped) {
                    $escaped = false;
                    continue;
                }
                if ($char === '\\' && $quote !== '`') {
                    $escaped = true;
                    continue;
                }
                if ($char === $quote) {
                    if ($next === $quote) {
                        $buffer .= $next;
                        $i++;
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }

            if ($char === '#' || ($char === '-' && $next === '-' && ($i + 2 >= $length || ctype_space($sql[$i + 2])))) {
                $lineComment = true;
                if ($char === '-') {
                    $i++;
                }
                continue;
            }
            if ($char === '/' && $next === '*') {
                $blockComment = true;
                $i++;
                continue;
            }
            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $buffer .= $char;
                continue;
            }
            if ($char === ';') {
                $statement = trim($buffer);
                $buffer = '';
                if ($statement !== '') {
                    yield $statement;
                }
                continue;
            }
            $buffer .= $char;
        }

        if (trim($buffer) !== '') {
            yield trim($buffer);
        }
    }

    private function validateArchive(ZipArchive $zip): void
    {
        $total = 0;
        $required = ['manifest.json' => false, 'database.sql' => false];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = str_replace('\\', '/', (string) ($stat['name'] ?? ''));
            $parts = explode('/', trim($name, '/'));
            if ($name === '' || str_starts_with($name, '/') || str_contains($name, "\0") || in_array('..', $parts, true)) {
                throw new RuntimeException('The ZIP contains an unsafe path.');
            }
            if (!isset($required[$name]) && !str_starts_with($name, 'runtime/')) {
                throw new RuntimeException('The ZIP contains an unsupported file.');
            }
            if (isset($required[$name])) {
                $required[$name] = true;
            }
            $total += (int) ($stat['size'] ?? 0);
            if ($total > self::MAX_UNCOMPRESSED_BYTES) {
                throw new RuntimeException('The uncompressed backup is too large.');
            }
        }
        if (in_array(false, $required, true)) {
            throw new RuntimeException('The ZIP is missing manifest.json or database.sql.');
        }
    }

    private function extractArchive(ZipArchive $zip, string $stage): void
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = str_replace('\\', '/', (string) ($stat['name'] ?? ''));
            if (str_ends_with($name, '/')) {
                continue;
            }
            $target = $stage . '/' . $name;
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0700, true);
            }
            $input = $zip->getStream($name);
            $output = fopen($target, 'wb');
            if ($input === false || $output === false) {
                throw new RuntimeException("Unable to extract {$name}");
            }
            stream_copy_to_stream($input, $output);
            fclose($input);
            fclose($output);
        }
    }

    private function restoreRuntime(string $runtime): void
    {
        $persistentVpn = $this->dataRoot . '/vpnbot';
        foreach (scandir($persistentVpn) ?: [] as $item) {
            if ($item !== '.' && $item !== '..' && !in_array($item, ['Default', 'update', 'index.php'], true)) {
                $this->removeTree($persistentVpn . '/' . $item);
            }
        }
        if (is_dir($runtime . '/vpnbot')) {
            foreach (scandir($runtime . '/vpnbot') ?: [] as $agent) {
                if ($agent !== '.' && $agent !== '..' && preg_match('/^\d+[A-Za-z0-9_]+$/', $agent)) {
                    $this->copyTree($runtime . '/vpnbot/' . $agent, $persistentVpn . '/' . $agent);
                }
            }
        }

        foreach (['storage', 'cronstate'] as $directory) {
            $target = $this->dataRoot . '/' . $directory;
            $this->removeTree($target);
            if (is_dir($runtime . '/' . $directory)) {
                $this->copyTree($runtime . '/' . $directory, $target);
            } else {
                mkdir($target, 0775, true);
            }
        }

        foreach (['images.jpg', 'custom.jpg', 'api_hash.txt', 'bot_username'] as $file) {
            $target = $this->dataRoot . '/' . $file;
            @unlink($target);
            if (is_file($runtime . '/' . $file)) {
                copy($runtime . '/' . $file, $target);
            }
        }
    }

    private function addTree(ZipArchive $zip, string $source, string $prefix): void
    {
        if (!is_dir($source) || is_link($source)) {
            return;
        }
        foreach (scandir($source) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $source . '/' . $item;
            $name = $prefix . '/' . $item;
            if (is_link($path)) {
                continue;
            }
            if (is_dir($path)) {
                $zip->addEmptyDir($name);
                $this->addTree($zip, $path, $name);
            } elseif (is_file($path)) {
                $zip->addFile($path, $name);
            }
        }
    }

    private function copyTree(string $source, string $destination): void
    {
        if (is_file($source)) {
            if (!is_dir(dirname($destination))) {
                mkdir(dirname($destination), 0775, true);
            }
            if (!copy($source, $destination)) {
                throw new RuntimeException("Unable to restore {$destination}");
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
                $this->copyTree($source . '/' . $item, $destination . '/' . $item);
            }
        }
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $item) {
            if ($item !== '.' && $item !== '..') {
                $this->removeTree($path . '/' . $item);
            }
        }
        @rmdir($path);
    }

    private function pruneEmergencyBackups(): void
    {
        $files = glob($this->dataRoot . '/backups/pre-restore-*.zip') ?: [];
        usort($files, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
        foreach (array_slice($files, 3) as $file) {
            @unlink($file);
        }
    }
}

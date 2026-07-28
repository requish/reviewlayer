<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once dirname(__DIR__) . '/api/bootstrap.php';

if ($argc !== 2 || preg_match('/^reviewlayer-backup-[a-zA-Z0-9T.-]+\.json$/D', (string) $argv[1]) !== 1) {
    fwrite(STDERR, "Usage: php tools/restore.php reviewlayer-backup-...json\n");
    exit(2);
}

$backupDirectory = realpath(dirname(__DIR__) . '/data/backups');
$backupPath = realpath(dirname(__DIR__) . '/data/backups/' . basename((string) $argv[1]));
if ($backupDirectory === false || $backupPath === false || !str_starts_with($backupPath, $backupDirectory . DIRECTORY_SEPARATOR)) {
    fwrite(STDERR, "Backup file was not found in data/backups.\n");
    exit(2);
}

try {
    $raw = file_get_contents($backupPath);
    if ($raw === false) throw new RuntimeException('Unable to read the backup.');
    $backup = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    $formatVersion = is_array($backup) ? (int) ($backup['format_version'] ?? 0) : 0;
    if (!is_array($backup) || $formatVersion < 1 || $formatVersion > 4) {
        throw new RuntimeException('Unsupported backup format.');
    }
    $storage = ReviewLayer\createStorage(ReviewLayer\loadConfig());
    $storage->restoreAll($backup);
    fwrite(STDOUT, "ReviewLayer backup restored successfully.\n");
} catch (Throwable $error) {
    fwrite(STDERR, "Restore failed: " . $error->getMessage() . "\n");
    exit(1);
}

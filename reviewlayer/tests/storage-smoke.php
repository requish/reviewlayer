<?php

declare(strict_types=1);

use ReviewLayer\BackupService;
use ReviewLayer\ClearService;
use ReviewLayer\JsonStorage;
use ReviewLayer\UsageLimitException;
use ReviewLayer\UsageLimits;

require_once dirname(__DIR__) . '/api/bootstrap.php';

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'reviewlayer-json-' . bin2hex(random_bytes(6));
$backupDirectory = $directory . DIRECTORY_SEPARATOR . 'backups';
if (!mkdir($backupDirectory, 0700, true) && !is_dir($backupDirectory)) {
    throw new RuntimeException('Unable to create test directory.');
}

$storagePath = $directory . DIRECTORY_SEPARATOR . 'reviewlayer.json';
$logPath = $directory . DIRECTORY_SEPARATOR . 'admin.log';
$storage = new JsonStorage($storagePath, $logPath);
$authorId = '11111111-1111-4111-8111-111111111111';
$pinId = '22222222-2222-4222-8222-222222222222';
$now = gmdate('c');

try {
    $pin = [
        'id' => $pinId,
        'project_key' => 'json-smoke',
        'page_key' => 'https://example.com/',
        'page_url' => 'https://example.com/',
        'status' => 'open',
        'author_id' => $authorId,
        'author_name' => 'JSON Tester',
        'target_selector' => '#hero',
        'target_fingerprint' => ['tag' => 'section', 'id' => 'hero'],
        'anchor' => ['relative_x' => 0.5, 'relative_y' => 0.5, 'document_x' => 100, 'document_y' => 100],
        'viewport' => ['width' => 1440, 'height' => 900, 'device_type' => 'desktop'],
        'browser' => ['browser' => 'Test', 'engine' => 'Test', 'os' => 'Test'],
        'created_at' => $now,
        'updated_at' => $now,
        'deleted_at' => null,
    ];
    $message = [
        'id' => '33333333-3333-4333-8333-333333333333',
        'pin_id' => $pinId,
        'author_id' => $authorId,
        'author_name' => 'JSON Tester',
        'message' => 'First message',
        'created_at' => $now,
        'updated_at' => $now,
        'deleted_at' => null,
    ];
    $created = $storage->createPin($pin, $message);
    if ($created['pin_number'] !== 1 || count($storage->listPins('json-smoke', 'https://example.com/')) !== 1) {
        throw new RuntimeException('JSON create/list assertion failed.');
    }
    if (count($storage->listPins('json-smoke', 'http://www.example.com/')) !== 1) {
        throw new RuntimeException('HTTP/HTTPS and www page aliases were not matched.');
    }
    if (count($storage->listPins('json-smoke', 'http://www.example.com:8080/')) !== 0) {
        throw new RuntimeException('A non-default port was incorrectly treated as a page alias.');
    }

    $secondPinId = '55555555-5555-4555-8555-555555555555';
    $secondPin = $pin;
    $secondPin['id'] = $secondPinId;
    $secondPin['page_key'] = 'https://example.com/contact';
    $secondPin['page_url'] = 'https://example.com/contact';
    $secondMessage = $message;
    $secondMessage['id'] = '66666666-6666-4666-8666-666666666666';
    $secondMessage['pin_id'] = $secondPinId;
    $secondMessage['message'] = 'Second page message';
    $secondCreated = $storage->createPin($secondPin, $secondMessage);
    $projectPins = $storage->listProjectPins('json-smoke');
    if ($secondCreated['pin_number'] !== 2 || count($projectPins) !== 2 || $projectPins[0]['id'] !== $secondPinId) {
        throw new RuntimeException('JSON project pin list assertion failed.');
    }

    $storage->addMessage([
        'id' => '44444444-4444-4444-8444-444444444444',
        'pin_id' => $pinId,
        'project_key' => 'json-smoke',
        'author_id' => $authorId,
        'author_name' => 'JSON Tester',
        'message' => 'Second message',
        'created_at' => gmdate('c'),
        'updated_at' => gmdate('c'),
        'deleted_at' => null,
    ]);
    $storage->updatePinStatus($pinId, 'json-smoke', 'resolved', gmdate('c'));

    $limits = new UsageLimits($storage, [
        'MAX_PINS_PER_AUTHOR' => 2,
        'MAX_MESSAGES_PER_PIN' => 2,
        'MAX_TOTAL_PINS' => 10,
        'MAX_TOTAL_MESSAGES' => 10,
    ]);
    $pinLimitReached = false;
    $messageLimitReached = false;
    try {
        $limits->assertCanCreatePin('json-smoke', $authorId);
    } catch (UsageLimitException) {
        $pinLimitReached = true;
    }
    try {
        $limits->assertCanAddMessage($pinId);
    } catch (UsageLimitException) {
        $messageLimitReached = true;
    }
    if (!$pinLimitReached || !$messageLimitReached) {
        throw new RuntimeException('Configured usage limits were not enforced.');
    }

    $manualBackupFile = (new BackupService($backupDirectory, 1))->create($storage);
    if (!is_file($backupDirectory . DIRECTORY_SEPARATOR . $manualBackupFile)) {
        throw new RuntimeException('Manual JSON backup assertion failed.');
    }

    $clear = new ClearService(
        $storage,
        new BackupService($backupDirectory, 1),
        ['CREATE_BACKUP_BEFORE_PURGE' => true]
    );
    $result = $clear->execute('all_projects', 'purge', 'json-smoke', 'https://example.com/', false, hash('sha256', 'test'));
    if ($result['deleted_pins'] !== 2 || $result['deleted_messages'] !== 3 || !is_string($result['backup_file'])) {
        throw new RuntimeException('JSON clear/backup assertion failed.');
    }
    if (count(glob($backupDirectory . DIRECTORY_SEPARATOR . 'reviewlayer-backup-*.json') ?: []) !== 1) {
        throw new RuntimeException('Backup retention limit assertion failed.');
    }

    $backupPath = $backupDirectory . DIRECTORY_SEPARATOR . $result['backup_file'];
    $backup = json_decode((string) file_get_contents($backupPath), true, 64, JSON_THROW_ON_ERROR);
    $storage->restoreAll($backup);
    $restored = $storage->getPin($pinId, 'json-smoke');
    if ($restored === null || $restored['status'] !== 'resolved' || count($restored['messages']) !== 2 || count($storage->listProjectPins('json-smoke')) !== 2) {
        throw new RuntimeException('JSON restore assertion failed.');
    }

    fwrite(STDOUT, "JSON storage, backup, purge, and restore smoke test passed.\n");
} finally {
    foreach (glob($backupDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
        if (is_file($file)) unlink($file);
    }
    foreach ([$storagePath, $storagePath . '.lock', $logPath] as $file) {
        if (is_file($file)) unlink($file);
    }
    if (is_dir($backupDirectory)) rmdir($backupDirectory);
    if (is_dir($directory)) rmdir($directory);
}

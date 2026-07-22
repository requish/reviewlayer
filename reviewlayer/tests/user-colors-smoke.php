<?php

declare(strict_types=1);

use ReviewLayer\Database;
use ReviewLayer\JsonStorage;
use ReviewLayer\StorageInterface;

require_once dirname(__DIR__) . '/api/bootstrap.php';

/** @param StorageInterface $storage */
function verifyUserColors(StorageInterface $storage, string $projectKey): void
{
    $pinId = '30000000-0000-4000-8000-000000000001';
    $firstAuthorId = '10000000-0000-4000-8000-000000000001';
    $createdAt = '2026-01-01T00:00:01Z';
    $pin = [
        'id' => $pinId,
        'project_key' => $projectKey,
        'page_key' => 'https://example.com/',
        'page_url' => 'https://example.com/',
        'status' => 'open',
        'author_id' => $firstAuthorId,
        'author_name' => 'User 1',
        'target_selector' => '#hero',
        'target_fingerprint' => ['tag' => 'section'],
        'anchor' => ['relative_x' => 0.5, 'relative_y' => 0.5],
        'viewport' => ['width' => 1440, 'height' => 900, 'device_type' => 'desktop'],
        'browser' => ['browser' => 'Test', 'engine' => 'Test', 'os' => 'Test'],
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
        'deleted_at' => null,
    ];
    $message = [
        'id' => '20000000-0000-4000-8000-000000000001',
        'pin_id' => $pinId,
        'author_id' => $firstAuthorId,
        'author_name' => 'User 1',
        'message' => 'Message 1',
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
        'deleted_at' => null,
    ];
    $created = $storage->createPin($pin, $message);
    if (($created['author_color_index'] ?? null) !== 1) {
        throw new RuntimeException($storage->mode() . ': first user did not receive color 1.');
    }

    for ($number = 2; $number <= 11; $number++) {
        $suffix = str_pad((string) $number, 12, '0', STR_PAD_LEFT);
        $timestamp = sprintf('2026-01-01T00:00:%02dZ', $number);
        $added = $storage->addMessage([
            'id' => '20000000-0000-4000-8000-' . $suffix,
            'pin_id' => $pinId,
            'project_key' => $projectKey,
            'author_id' => '10000000-0000-4000-8000-' . $suffix,
            'author_name' => 'User ' . $number,
            'message' => 'Message ' . $number,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
            'deleted_at' => null,
        ]);
        $expected = (($number - 1) % 10) + 1;
        if (($added['author_color_index'] ?? null) !== $expected) {
            throw new RuntimeException($storage->mode() . ': user color sequence is invalid at user ' . $number . '.');
        }
    }

    $users = $storage->listProjectUsers($projectKey);
    $colors = array_column($users, 'color_index');
    if (count($users) !== 11 || $colors !== [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 1]) {
        throw new RuntimeException($storage->mode() . ': project user list or color cycle is invalid.');
    }
    if (array_key_exists('author_id', $users[0])) {
        throw new RuntimeException($storage->mode() . ': project user API data exposes a private author ID.');
    }

    $loaded = $storage->getPin($pinId, $projectKey);
    if (!is_array($loaded) || count($loaded['messages'] ?? []) !== 11) {
        throw new RuntimeException($storage->mode() . ': colored conversation could not be loaded.');
    }
    if (array_column($loaded['messages'], 'author_color_index') !== [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 1]) {
        throw new RuntimeException($storage->mode() . ': conversation message colors are invalid.');
    }
    $projectSummary = $storage->listProjectPins($projectKey)[0] ?? [];
    if (($storage->listPins($projectKey, 'https://example.com/')[0]['author_color_index'] ?? null) !== 1
        || ($projectSummary['author_color_index'] ?? null) !== 1) {
        throw new RuntimeException($storage->mode() . ': pin summaries do not contain the author color.');
    }
    if (($projectSummary['message_count'] ?? null) !== 11
        || ($projectSummary['last_message_at'] ?? '') !== '2026-01-01T00:00:11Z') {
        throw new RuntimeException($storage->mode() . ': project pin summaries do not contain the latest reply state.');
    }
    if (($storage->listPins($projectKey, 'http://www.example.com/')[0]['id'] ?? null) !== $pinId) {
        throw new RuntimeException($storage->mode() . ': HTTP/HTTPS and www page aliases were not matched.');
    }
    if ($storage->listPins($projectKey, 'http://www.example.com:8080/') !== []) {
        throw new RuntimeException($storage->mode() . ': non-default ports were incorrectly merged.');
    }

    $storage->updatePinStatus($pinId, $projectKey, 'resolved', $firstAuthorId, '2026-01-01T00:00:12Z');
    $storage->updatePinStatus($pinId, $projectKey, 'open', '10000000-0000-4000-8000-000000000002', '2026-01-01T00:00:13Z');
    $statusEvents = $storage->listPinStatusEvents($pinId, $projectKey);
    if (array_column($statusEvents, 'status') !== ['resolved', 'open']) {
        throw new RuntimeException($storage->mode() . ': status event history is invalid.');
    }

    $backup = $storage->exportAll();
    if (($backup['format_version'] ?? null) !== 3 || count($backup['users'] ?? []) !== 11 || count($backup['status_events'] ?? []) !== 2) {
        throw new RuntimeException($storage->mode() . ': backup does not preserve project users.');
    }

    $legacyBackup = $backup;
    $legacyBackup['format_version'] = 2;
    unset($legacyBackup['status_events']);
    $storage->restoreAll($legacyBackup);
    if ($storage->getPin($pinId, $projectKey) === null || $storage->listPinStatusEvents($pinId, $projectKey) !== []) {
        throw new RuntimeException($storage->mode() . ': a version 2 backup was not restored compatibly.');
    }

    $cleared = $storage->clear('current_page', 'soft', $projectKey, 'http://www.example.com/', '2026-01-02T00:00:00Z');
    if (($cleared['pins'] ?? 0) !== 1 || $storage->listPins($projectKey, 'https://example.com/') !== []) {
        throw new RuntimeException($storage->mode() . ': current-page clearing did not honor page aliases.');
    }
}

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'reviewlayer-user-colors-' . bin2hex(random_bytes(6));
if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
    throw new RuntimeException('Unable to create test directory.');
}

try {
    verifyUserColors(new JsonStorage($directory . '/reviewlayer.json', $directory . '/admin.log'), 'json-users');
    if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        verifyUserColors(new Database($directory . '/reviewlayer.sqlite'), 'sqlite-users');
    }
    echo "Commenter color assignment smoke test passed.\n";
} finally {
    foreach (glob($directory . '/*') ?: [] as $file) {
        if (is_file($file)) unlink($file);
    }
    if (is_dir($directory)) rmdir($directory);
}

<?php

declare(strict_types=1);

use ReviewLayer\Database;
use ReviewLayer\JsonStorage;
use ReviewLayer\StorageInterface;

require_once dirname(__DIR__) . '/api/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function linkingAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

/** @param array<string, mixed> $record @return array<string, mixed> */
function withoutAuthorIdentity(array $record): array
{
    unset($record['author_id'], $record['author_name'], $record['author_color_index']);
    return $record;
}

/** @param StorageInterface $storage */
function verifyAuthorLinking(StorageInterface $storage): void
{
    $projectKey = 'linking-test';
    $canonicalId = '10000000-0000-4000-8000-000000000001';
    $sourceId = '10000000-0000-4000-8000-000000000002';
    $firstPinId = '30000000-0000-4000-8000-000000000001';
    $secondPinId = '30000000-0000-4000-8000-000000000002';
    $basePin = [
        'project_key' => $projectKey,
        'page_key' => 'https://example.com/',
        'page_url' => 'https://example.com/',
        'status' => 'open',
        'target_selector' => '#target',
        'target_fingerprint' => ['tag' => 'section'],
        'anchor' => ['relative_x' => 0.5, 'relative_y' => 0.5],
        'viewport' => ['width' => 1440, 'height' => 900, 'device_type' => 'desktop'],
        'browser' => ['browser' => 'Test', 'engine' => 'Test', 'os' => 'Test'],
        'deleted_at' => null,
    ];
    $storage->createPin(array_merge($basePin, [
        'id' => $firstPinId,
        'author_id' => $canonicalId,
        'author_name' => 'Canonical User',
        'created_at' => '2026-01-01T00:00:00Z',
        'updated_at' => '2026-01-01T00:00:00Z',
    ]), [
        'id' => '20000000-0000-4000-8000-000000000001',
        'pin_id' => $firstPinId,
        'author_id' => $canonicalId,
        'author_name' => 'Canonical User',
        'message' => 'Canonical first message',
        'created_at' => '2026-01-01T00:00:00Z',
        'updated_at' => '2026-01-01T00:00:00Z',
        'deleted_at' => null,
    ]);
    $storage->createPin(array_merge($basePin, [
        'id' => $secondPinId,
        'status' => 'resolved',
        'target_selector' => '#second-target',
        'author_id' => $sourceId,
        'author_name' => 'Mobile Alias',
        'created_at' => '2026-01-02T00:00:00Z',
        'updated_at' => '2026-01-04T00:00:00Z',
    ]), [
        'id' => '20000000-0000-4000-8000-000000000002',
        'pin_id' => $secondPinId,
        'author_id' => $sourceId,
        'author_name' => 'Mobile Alias',
        'message' => 'Source device message',
        'created_at' => '2026-01-02T00:00:00Z',
        'updated_at' => '2026-01-02T00:00:00Z',
        'deleted_at' => null,
    ]);
    $storage->addMessage([
        'id' => '20000000-0000-4000-8000-000000000003',
        'pin_id' => $firstPinId,
        'project_key' => $projectKey,
        'author_id' => $sourceId,
        'author_name' => 'Mobile Alias',
        'message' => 'Source reply on canonical pin',
        'created_at' => '2026-01-03T00:00:00Z',
        'updated_at' => '2026-01-03T00:00:00Z',
        'deleted_at' => null,
    ]);

    $before = $storage->exportAll();
    linkingAssert($storage->mergeProjectAuthors($projectKey, $canonicalId, $sourceId), $storage->mode() . ': author merge did not run.');
    linkingAssert(!$storage->mergeProjectAuthors($projectKey, $canonicalId, $sourceId), $storage->mode() . ': completed merge was not idempotent.');
    $after = $storage->exportAll();

    $beforePins = array_map('withoutAuthorIdentity', $before['pins']);
    $afterPins = array_map('withoutAuthorIdentity', $after['pins']);
    $beforeMessages = array_map('withoutAuthorIdentity', $before['messages']);
    $afterMessages = array_map('withoutAuthorIdentity', $after['messages']);
    linkingAssert($beforePins === $afterPins, $storage->mode() . ': pin content, numbering, status, or timestamps changed during linking.');
    linkingAssert($beforeMessages === $afterMessages, $storage->mode() . ': message content or timestamps changed during linking.');

    $users = $storage->listProjectUserRecords($projectKey);
    linkingAssert(count($users) === 1, $storage->mode() . ': duplicate project user was not removed.');
    linkingAssert(($users[0]['author_id'] ?? '') === $canonicalId, $storage->mode() . ': canonical user ID was not preserved.');
    linkingAssert(($users[0]['author_name'] ?? '') === 'Canonical User', $storage->mode() . ': canonical name was not preserved.');
    linkingAssert(($users[0]['color_index'] ?? 0) === 1, $storage->mode() . ': canonical color was not preserved.');

    foreach ([$firstPinId, $secondPinId] as $pinId) {
        $pin = $storage->getPin($pinId, $projectKey);
        linkingAssert(is_array($pin), $storage->mode() . ': linked pin is missing.');
        linkingAssert(($pin['author_id'] ?? '') === $canonicalId, $storage->mode() . ': pin author was not canonicalized.');
        linkingAssert(($pin['author_name'] ?? '') === 'Canonical User', $storage->mode() . ': pin author name was not canonicalized.');
        foreach (($pin['messages'] ?? []) as $message) {
            linkingAssert(($message['author_id'] ?? '') === $canonicalId, $storage->mode() . ': message author was not canonicalized.');
            linkingAssert(($message['author_name'] ?? '') === 'Canonical User', $storage->mode() . ': message author name was not canonicalized.');
        }
    }
}

$temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'reviewlayer-author-linking-' . bin2hex(random_bytes(6));
if (!mkdir($temporary, 0700, true) && !is_dir($temporary)) {
    throw new RuntimeException('Unable to create the author linking test directory.');
}

try {
    verifyAuthorLinking(new JsonStorage(
        $temporary . DIRECTORY_SEPARATOR . 'reviewlayer.json',
        $temporary . DIRECTORY_SEPARATOR . 'admin.log'
    ));
    if (extension_loaded('pdo_sqlite')) {
        verifyAuthorLinking(new Database($temporary . DIRECTORY_SEPARATOR . 'reviewlayer.sqlite'));
    }
    echo "ReviewLayer author linking smoke test passed.\n";
} finally {
    foreach (glob($temporary . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
        if (is_file($file)) unlink($file);
    }
    foreach (glob($temporary . DIRECTORY_SEPARATOR . '.*') ?: [] as $file) {
        if (is_file($file)) unlink($file);
    }
    rmdir($temporary);
}

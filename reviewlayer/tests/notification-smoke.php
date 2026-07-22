<?php

declare(strict_types=1);

use ReviewLayer\NotificationService;
use ReviewLayer\StorageInterface;

require_once dirname(__DIR__) . '/api/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function notificationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$authorId = '11111111-1111-4111-8111-111111111111';
$secondAuthorId = '22222222-2222-4222-8222-222222222222';
$temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'reviewlayer-notification-' . bin2hex(random_bytes(6));
if (!mkdir($temporary, 0700, true) && !is_dir($temporary)) {
    throw new RuntimeException('Unable to create the notification test directory.');
}

$storage = new class($authorId, $secondAuthorId) implements StorageInterface {
    private bool $active = true;
    /** @var array<string, array<string, mixed>> */
    private array $users;
    public int $mergeCount = 0;
    public function __construct(private readonly string $authorId, private readonly string $secondAuthorId)
    {
        $this->users = [
            $authorId => [
                'author_id' => $authorId,
                'author_name' => 'Test User',
                'color_index' => 1,
                'sequence_number' => 1,
                'created_at' => '2026-01-01T00:00:00Z',
                'updated_at' => '2026-01-01T00:00:00Z',
            ],
            $secondAuthorId => [
                'author_id' => $secondAuthorId,
                'author_name' => 'Mobile Alias',
                'color_index' => 2,
                'sequence_number' => 2,
                'created_at' => '2026-01-02T00:00:00Z',
                'updated_at' => '2026-01-02T00:00:00Z',
            ],
        ];
    }
    public function deactivate(): void { $this->active = false; }
    public function mode(): string { return 'test'; }
    public function listPins(string $projectKey, string $pageKey): array
    {
        return [
            ['id' => '33333333-3333-4333-8333-333333333333', 'pin_number' => 2],
            ['id' => '44444444-4444-4444-8444-444444444444', 'pin_number' => 3],
        ];
    }
    public function listProjectPins(string $projectKey): array { return []; }
    public function listProjectUsers(string $projectKey): array { return []; }
    public function listProjectUserRecords(string $projectKey): array
    {
        if (!$this->active) return [];
        return array_values($this->users);
    }
    public function mergeProjectAuthors(string $projectKey, string $canonicalAuthorId, string $sourceAuthorId): bool
    {
        if ($projectKey !== 'default' || !isset($this->users[$canonicalAuthorId], $this->users[$sourceAuthorId])) return false;
        unset($this->users[$sourceAuthorId]);
        $this->mergeCount++;
        return true;
    }
    public function getPin(string $id, string $projectKey): ?array
    {
        if ($id === '33333333-3333-4333-8333-333333333333') {
            return ['messages' => [['message' => 'First'], ['message' => 'Reply']]];
        }
        if ($id === '44444444-4444-4444-8444-444444444444') {
            return ['messages' => [['message' => 'First']]];
        }
        return null;
    }
    public function getMessage(string $id, string $projectKey): ?array { return null; }
    public function createPin(array $pin, array $message): array { return $pin; }
    public function addMessage(array $message): array { return $message; }
    public function updatePinStatus(string $id, string $projectKey, string $status, string $updatedAt): bool { return false; }
    public function softDeletePin(string $id, string $projectKey, string $deletedAt): bool { return false; }
    public function softDeleteMessage(string $id, string $projectKey, string $deletedAt): bool { return false; }
    public function exportAll(): array { return []; }
    public function restoreAll(array $backup): void {}
    public function clear(string $scope, string $mode, string $projectKey, string $pageKey, string $timestamp): array { return ['pins' => 0, 'messages' => 0]; }
    public function logAdmin(array $event): void {}
};

$_SERVER['HTTP_HOST'] = 'reviewlayer.example.com';
$_SERVER['SCRIPT_NAME'] = '/reviewlayer/api/index.php';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

$config = ReviewLayer\loadConfig();
$config['NOTIFICATIONS_ENABLED'] = true;
$config['NOTIFICATION_FROM_EMAIL'] = 'reviewlayer@example.com';
$config['NOTIFICATION_ENCRYPTION_KEY'] = base64_encode(random_bytes(32));

try {
    $service = new NotificationService($temporary, $config);
    notificationAssert($service->isAvailable(), 'Notification service should be available in the smoke test.');
    $notificationItems = new ReflectionMethod($service, 'notificationItems');
    $items = $notificationItems->invoke($service, $storage, 'default', 'https://reviewlayer.example.com/');
    notificationAssert($items === [
        ['pin_number' => 3, 'has_reply' => false],
        ['pin_number' => 2, 'has_reply' => true],
    ], 'Notification items must list the newest pin first and identify replies without exposing content.');
    $mailer = new ReviewLayer\NativeMailService($config);
    $notificationDigest = new ReflectionMethod($mailer, 'notificationDigest');
    notificationAssert(
        $notificationDigest->invoke($mailer, $items, false) === "Pin #3\nPin #2 — reply",
        'The English notification digest must contain pin metadata only.'
    );
    notificationAssert(
        $notificationDigest->invoke($mailer, $items, true) === "Pinezka #3\nPinezka #2 — odpowiedź",
        'The Polish notification digest must contain pin metadata only.'
    );
    $firstSecret = str_repeat('a', 64);
    $secondSecret = str_repeat('b', 64);
    $settings = $service->settings($storage, 'default', $authorId, $firstSecret, 'en');
    notificationAssert($settings['email_verified'] === false, 'A new notification profile must not expose an email.');
    $service->settings($storage, 'default', $secondAuthorId, $secondSecret, 'en');

    $encrypt = new ReflectionMethod($service, 'encrypt');
    $decrypt = new ReflectionMethod($service, 'decrypt');
    $ciphertext = $encrypt->invoke($service, 'person@example.com');
    notificationAssert(is_string($ciphertext) && !str_contains($ciphertext, 'person@example.com'), 'Email ciphertext must not contain plaintext.');
    notificationAssert($decrypt->invoke($service, $ciphertext) === 'person@example.com', 'Encrypted email must decrypt with the server key.');

    $notificationPath = $temporary . DIRECTORY_SEPARATOR . 'notifications.json';
    $storedData = json_decode((string) file_get_contents($notificationPath), true, 32, JSON_THROW_ON_ERROR);
    $verificationSecret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $secondRecipientId = '';
    foreach ($storedData['profiles'] as &$profile) {
        if ($profile['author_id'] === $authorId) {
            $profile['email_ciphertext'] = $ciphertext;
            $profile['email_verified_at'] = '2026-01-03T00:00:00Z';
        }
        if ($profile['author_id'] === $secondAuthorId) {
            $secondRecipientId = (string) $profile['recipient_id'];
            $profile['pending_email_ciphertext'] = $ciphertext;
            $profile['verification_token_hash'] = hash('sha256', $verificationSecret);
            $profile['verification_expires_at'] = gmdate('c', time() + 600);
        }
    }
    unset($profile);
    file_put_contents(
        $notificationPath,
        json_encode($storedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        LOCK_EX
    );
    notificationAssert($secondRecipientId !== '', 'The second device profile must have a recipient ID.');

    $verification = $service->verify($storage, $secondRecipientId . '.' . $verificationSecret);
    notificationAssert(($verification['linked'] ?? false) === true, 'The second device must link to the verified commenter.');
    notificationAssert(($verification['canonical_author_id'] ?? '') === $authorId, 'The older verified commenter must remain canonical.');
    notificationAssert($service->resolveAuthorId('default', $secondAuthorId) === $authorId, 'The old browser author ID must resolve to the canonical ID.');
    notificationAssert($storage->mergeCount === 1, 'Storage authors must be merged exactly once.');

    $linkedSettings = $service->settings($storage, 'default', $secondAuthorId, $secondSecret, 'en');
    notificationAssert(($linkedSettings['canonical_author_id'] ?? '') === $authorId, 'Settings must return the canonical author ID to the second browser.');
    notificationAssert(($linkedSettings['author_name'] ?? '') === 'Test User', 'The second browser must inherit the canonical commenter name.');
    notificationAssert(($linkedSettings['linked_devices'] ?? 0) === 2, 'Both browser identity secrets must remain authorized.');
    notificationAssert($service->synchronizeLinkedAuthors($storage) === 0, 'A completed author merge must not run again.');

    $stored = file_get_contents($notificationPath);
    notificationAssert(is_string($stored) && !str_contains($stored, 'person@example.com'), 'The notification store must not contain a plaintext email.');
    $storage->deactivate();
    notificationAssert($service->pruneOrphanedProfiles($storage) === 1, 'An orphaned notification profile must be removed.');
    $stored = file_get_contents($notificationPath);
    $decoded = is_string($stored) ? json_decode($stored, true, 32, JSON_THROW_ON_ERROR) : [];
    notificationAssert(($decoded['profiles'] ?? null) === [], 'The notification store must not retain a removed commenter profile.');
    echo "ReviewLayer notification smoke test passed.\n";
} finally {
    foreach (glob($temporary . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
        if (is_file($file)) unlink($file);
    }
    foreach (glob($temporary . DIRECTORY_SEPARATOR . '.*') ?: [] as $file) {
        if (is_file($file)) unlink($file);
    }
    rmdir($temporary);
}

<?php

declare(strict_types=1);

namespace ReviewLayer;

use RuntimeException;
use Throwable;

const REVIEWLAYER_VERSION = '1.4.1';

require_once __DIR__ . '/StorageInterface.php';
require_once __DIR__ . '/Validation.php';
require_once __DIR__ . '/Security.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/JsonStorage.php';
require_once __DIR__ . '/BackupService.php';
require_once __DIR__ . '/ClearService.php';
require_once __DIR__ . '/UsageLimits.php';
require_once __DIR__ . '/NativeMailService.php';
require_once __DIR__ . '/NotificationService.php';

/** @return array<string, mixed> */
function loadConfig(): array
{
    $defaults = [
        'PROJECT_ACCESS_CODE' => '',
        'ADMIN_ACCESS_CODE' => '',
        'PROJECT_ACCESS_CODE_HASH' => '',
        'ADMIN_ACCESS_CODE_HASH' => '',
        'ALLOW_GUESTS' => true,
        'ALLOW_AUTHOR_DELETE_OWN_MESSAGES' => true,
        'ALLOW_AUTHOR_DELETE_OWN_PINS' => true,
        'CREATE_BACKUP_BEFORE_PURGE' => true,
        'ALLOWED_PROJECT_KEYS' => [],
        'ALLOWED_PAGE_HOSTS' => [],
        'REQUIRE_SAME_HOST_PAGE_URL' => true,
        'FORCE_SECURE_SESSION_COOKIE' => false,
        'DATA_DIRECTORY' => '',
        'STORAGE_MODE' => 'auto',
        'MOBILE_BREAKPOINT' => 600,
        'DESKTOP_BREAKPOINT' => 1024,
        'MAX_NAME_LENGTH' => 80,
        'MAX_MESSAGE_LENGTH' => 5000,
        'RATE_LIMIT_REQUESTS' => 30,
        'RATE_LIMIT_WINDOW_SECONDS' => 60,
        'READ_RATE_LIMIT_REQUESTS' => 300,
        'READ_RATE_LIMIT_WINDOW_SECONDS' => 60,
        'ADMIN_RATE_LIMIT_REQUESTS' => 10,
        'ADMIN_RATE_LIMIT_WINDOW_SECONDS' => 900,
        'ACCESS_RATE_LIMIT_REQUESTS' => 10,
        'ACCESS_RATE_LIMIT_WINDOW_SECONDS' => 900,
        'PERSISTENT_RATE_LIMIT' => true,
        'RATE_LIMIT_MAX_BUCKETS' => 5000,
        'CREATE_PIN_DAILY_LIMIT' => 200,
        'ADD_MESSAGE_DAILY_LIMIT' => 1000,
        'DAILY_RATE_LIMIT_WINDOW_SECONDS' => 86400,
        'MAX_PINS_PER_AUTHOR' => 500,
        'MAX_MESSAGES_PER_PIN' => 500,
        'MAX_TOTAL_PINS' => 10000,
        'MAX_TOTAL_MESSAGES' => 50000,
        'MAX_BACKUPS' => 20,
        'NOTIFICATIONS_ENABLED' => true,
        'NOTIFICATION_FROM_EMAIL' => '',
        'NOTIFICATION_FROM_NAME' => 'ReviewLayer',
        'NOTIFICATION_PUBLIC_BASE_URL' => '',
        'NOTIFICATION_ENCRYPTION_KEY' => '',
        'NOTIFICATION_COOLDOWN_SECONDS' => 900,
        'NOTIFICATION_HOURLY_LIMIT' => 5,
        'NOTIFICATION_DAILY_LIMIT' => 20,
        'NOTIFICATION_RECIPIENT_DAILY_LIMIT' => 20,
        'NOTIFICATION_IP_HOURLY_LIMIT' => 10,
        'NOTIFICATION_IP_DAILY_LIMIT' => 40,
        'EMAIL_VERIFICATION_COOLDOWN_SECONDS' => 300,
        'EMAIL_VERIFICATION_DAILY_LIMIT' => 5,
        'EMAIL_VERIFICATION_IP_DAILY_LIMIT' => 10,
        'EMAIL_VERIFICATION_TTL_SECONDS' => 1800,
    ];
    $configPath = dirname(__DIR__) . '/config.php';
    $custom = [];
    if (is_file($configPath)) {
        $loaded = require $configPath;
        if (!is_array($loaded)) {
            throw new RuntimeException('config.php must return an array.');
        }
        $custom = $loaded;
    }
    $config = array_replace($defaults, $custom);
    if (!in_array($config['STORAGE_MODE'], ['auto', 'sqlite', 'json'], true)) {
        throw new RuntimeException('STORAGE_MODE must be auto, sqlite or json.');
    }
    if ((int) $config['MOBILE_BREAKPOINT'] < 320 || (int) $config['DESKTOP_BREAKPOINT'] <= (int) $config['MOBILE_BREAKPOINT']) {
        throw new RuntimeException('Viewport breakpoints are invalid.');
    }
    if ((int) $config['MAX_NAME_LENGTH'] < 1 || (int) $config['MAX_MESSAGE_LENGTH'] < 1) {
        throw new RuntimeException('Text limits are invalid.');
    }
    foreach (['RATE_LIMIT_REQUESTS', 'RATE_LIMIT_WINDOW_SECONDS', 'READ_RATE_LIMIT_REQUESTS', 'READ_RATE_LIMIT_WINDOW_SECONDS', 'ADMIN_RATE_LIMIT_REQUESTS', 'ADMIN_RATE_LIMIT_WINDOW_SECONDS', 'ACCESS_RATE_LIMIT_REQUESTS', 'ACCESS_RATE_LIMIT_WINDOW_SECONDS', 'RATE_LIMIT_MAX_BUCKETS', 'CREATE_PIN_DAILY_LIMIT', 'ADD_MESSAGE_DAILY_LIMIT', 'DAILY_RATE_LIMIT_WINDOW_SECONDS', 'MAX_PINS_PER_AUTHOR', 'MAX_MESSAGES_PER_PIN', 'MAX_TOTAL_PINS', 'MAX_TOTAL_MESSAGES', 'MAX_BACKUPS'] as $limitKey) {
        if ((int) $config[$limitKey] < 0) {
            throw new RuntimeException($limitKey . ' must be zero or greater.');
        }
    }
    if (!is_array($config['ALLOWED_PROJECT_KEYS']) || !array_is_list($config['ALLOWED_PROJECT_KEYS'])) {
        throw new RuntimeException('ALLOWED_PROJECT_KEYS must be a list.');
    }
    Validation::configureAllowedProjectKeys([]);
    foreach ($config['ALLOWED_PROJECT_KEYS'] as $projectKey) {
        Validation::projectKey($projectKey);
    }
    Validation::configureAllowedProjectKeys($config['ALLOWED_PROJECT_KEYS']);
    Validation::trustedPageHosts($config);
    foreach (['NOTIFICATION_COOLDOWN_SECONDS', 'NOTIFICATION_HOURLY_LIMIT', 'NOTIFICATION_DAILY_LIMIT', 'NOTIFICATION_RECIPIENT_DAILY_LIMIT', 'NOTIFICATION_IP_HOURLY_LIMIT', 'NOTIFICATION_IP_DAILY_LIMIT', 'EMAIL_VERIFICATION_COOLDOWN_SECONDS', 'EMAIL_VERIFICATION_DAILY_LIMIT', 'EMAIL_VERIFICATION_IP_DAILY_LIMIT', 'EMAIL_VERIFICATION_TTL_SECONDS'] as $limitKey) {
        if ((int) $config[$limitKey] < 0) {
            throw new RuntimeException($limitKey . ' must be zero or greater.');
        }
    }
    return $config;
}

/** @param array<string, mixed> $config */
function dataDirectory(array $config): string
{
    $configured = trim((string) ($config['DATA_DIRECTORY'] ?? ''));
    if ($configured === '') {
        return dirname(__DIR__) . '/data';
    }
    $isAbsolute = preg_match('/^[a-zA-Z]:[\\\\\/]/D', $configured) === 1
        || str_starts_with($configured, '/')
        || str_starts_with($configured, '\\\\');
    if (!$isAbsolute || str_contains($configured, "\0")) {
        throw new RuntimeException('DATA_DIRECTORY must be an absolute path.');
    }
    return rtrim($configured, "\\/ ");
}

/** @param array<string, mixed> $config */
function createStorage(array $config): StorageInterface
{
    $dataDirectory = dataDirectory($config);
    if (!is_dir($dataDirectory) || !is_writable($dataDirectory)) {
        throw new RuntimeException('The data directory is not writable.');
    }
    $mode = (string) $config['STORAGE_MODE'];
    $sqliteAvailable = extension_loaded('pdo_sqlite');
    if ($mode === 'sqlite' && !$sqliteAvailable) {
        throw new RuntimeException('SQLite storage was requested but pdo_sqlite is unavailable.');
    }
    if ($mode === 'sqlite' || ($mode === 'auto' && $sqliteAvailable)) {
        return new Database($dataDirectory . '/reviewlayer.sqlite');
    }
    return new JsonStorage($dataDirectory . '/reviewlayer.json', $dataDirectory . '/admin.log');
}

/** @return array<string, mixed> */
function jsonBody(): array
{
    $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length > 131072) {
        throw new \InvalidArgumentException('Request body is too large.');
    }
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    if (strlen($raw) > 131072) {
        throw new \InvalidArgumentException('Request body is too large.');
    }
    $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($decoded) || array_is_list($decoded)) {
        throw new \InvalidArgumentException('JSON body must be an object.');
    }
    return $decoded;
}

/** @param array<string, mixed>|array<int, mixed>|null $data */
function respond(bool $success, array|null $data, ?array $error, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header("Content-Security-Policy: default-src 'none'; base-uri 'none'; frame-ancestors 'none'");
    header('Cross-Origin-Resource-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header('X-Frame-Options: DENY');
    echo json_encode(['success' => $success, 'data' => $data, 'error' => $error], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

function requireMethod(string ...$allowed): void
{
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, $allowed, true)) {
        header('Allow: ' . implode(', ', $allowed));
        respond(false, null, ['code' => 'METHOD_NOT_ALLOWED', 'message' => 'Method not allowed.'], 405);
    }
}

function uuidV4(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
}

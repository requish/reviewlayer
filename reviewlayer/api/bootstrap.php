<?php

declare(strict_types=1);

namespace ReviewLayer;

use RuntimeException;
use Throwable;

const REVIEWLAYER_VERSION = '1.2.9';

require_once __DIR__ . '/StorageInterface.php';
require_once __DIR__ . '/Validation.php';
require_once __DIR__ . '/Security.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/JsonStorage.php';
require_once __DIR__ . '/BackupService.php';
require_once __DIR__ . '/ClearService.php';
require_once __DIR__ . '/UsageLimits.php';

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
        'ALLOW_ADMIN_WITHOUT_CODE' => true,
        'CREATE_BACKUP_BEFORE_PURGE' => true,
        'STORAGE_MODE' => 'auto',
        'MOBILE_BREAKPOINT' => 600,
        'DESKTOP_BREAKPOINT' => 1024,
        'MAX_NAME_LENGTH' => 80,
        'MAX_MESSAGE_LENGTH' => 5000,
        'RATE_LIMIT_REQUESTS' => 30,
        'RATE_LIMIT_WINDOW_SECONDS' => 60,
        'PERSISTENT_RATE_LIMIT' => false,
        'MAX_PINS_PER_AUTHOR' => 0,
        'MAX_MESSAGES_PER_PIN' => 0,
        'MAX_TOTAL_PINS' => 0,
        'MAX_TOTAL_MESSAGES' => 0,
        'MAX_BACKUPS' => 0,
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
    foreach (['MAX_PINS_PER_AUTHOR', 'MAX_MESSAGES_PER_PIN', 'MAX_TOTAL_PINS', 'MAX_TOTAL_MESSAGES', 'MAX_BACKUPS'] as $limitKey) {
        if ((int) $config[$limitKey] < 0) {
            throw new RuntimeException($limitKey . ' must be zero or greater.');
        }
    }
    return $config;
}

/** @param array<string, mixed> $config */
function createStorage(array $config): StorageInterface
{
    $dataDirectory = dirname(__DIR__) . '/data';
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

<?php

declare(strict_types=1);

use ReviewLayer\BackupException;
use ReviewLayer\BackupService;
use ReviewLayer\ClearService;
use ReviewLayer\Security;
use ReviewLayer\SecurityException;
use ReviewLayer\StorageNotFoundException;
use ReviewLayer\UsageLimitException;
use ReviewLayer\UsageLimits;
use ReviewLayer\Validation;

require_once __DIR__ . '/bootstrap.php';

/** @param array<string, mixed> $value @return array<string, float|int|array<string, float|int>> */
function sanitizeAnchor(array $value): array
{
    $required = [
        'relative_x', 'relative_y', 'document_x', 'document_y', 'document_width', 'document_height',
        'viewport_width', 'viewport_height', 'scroll_x', 'scroll_y', 'device_pixel_ratio',
    ];
    $output = [];
    foreach ($required as $field) {
        if (!isset($value[$field]) || !is_numeric($value[$field]) || !is_finite((float) $value[$field])) {
            throw new InvalidArgumentException('anchor.' . $field . ' is invalid.');
        }
        $output[$field] = (float) $value[$field];
    }
    if ($output['relative_x'] < 0 || $output['relative_x'] > 1 || $output['relative_y'] < 0 || $output['relative_y'] > 1) {
        throw new InvalidArgumentException('Relative anchor coordinates are invalid.');
    }
    $rect = Validation::object($value['element_rect'] ?? null, 'anchor.element_rect', 2048);
    $output['element_rect'] = [];
    foreach (['x', 'y', 'width', 'height'] as $field) {
        if (!isset($rect[$field]) || !is_numeric($rect[$field]) || !is_finite((float) $rect[$field])) {
            throw new InvalidArgumentException('anchor.element_rect.' . $field . ' is invalid.');
        }
        $output['element_rect'][$field] = (float) $rect[$field];
    }
    return $output;
}

/** @param array<string, mixed> $value @param array<string, mixed> $config @return array<string, mixed> */
function sanitizeViewport(array $value, array $config): array
{
    $numbers = [];
    foreach (['width', 'height', 'document_width', 'document_height', 'scroll_x', 'scroll_y', 'device_pixel_ratio'] as $field) {
        if (!isset($value[$field]) || !is_numeric($value[$field]) || !is_finite((float) $value[$field])) {
            throw new InvalidArgumentException('viewport.' . $field . ' is invalid.');
        }
        $numbers[$field] = (float) $value[$field];
    }
    if ($numbers['width'] < 1 || $numbers['width'] > 20000 || $numbers['height'] < 1 || $numbers['height'] > 20000) {
        throw new InvalidArgumentException('Viewport dimensions are invalid.');
    }
    $width = (int) round($numbers['width']);
    $deviceType = $width < (int) $config['MOBILE_BREAKPOINT']
        ? 'mobile'
        : ($width < (int) $config['DESKTOP_BREAKPOINT'] ? 'tablet' : 'desktop');
    return [
        ...$numbers,
        'width' => $width,
        'height' => (int) round($numbers['height']),
        'orientation' => isset($value['orientation']) && is_string($value['orientation']) ? substr($value['orientation'], 0, 40) : '',
        'device_type' => $deviceType,
    ];
}

/** @param array<string, mixed> $value @return array<string, mixed> */
function sanitizeBrowser(array $value): array
{
    $result = [];
    foreach (['user_agent', 'browser', 'engine', 'os'] as $field) {
        $result[$field] = isset($value[$field]) && is_string($value[$field]) ? substr($value[$field], 0, $field === 'user_agent' ? 1024 : 100) : '';
    }
    $result['user_agent_data'] = isset($value['user_agent_data']) && is_array($value['user_agent_data'])
        ? Validation::object($value['user_agent_data'], 'browser.user_agent_data', 8192)
        : null;
    return $result;
}

/** @param array<string, mixed> $message @return array<string, mixed> */
function publicMessage(array $message): array
{
    unset($message['author_id']);
    return $message;
}

/** @param array<string, mixed> $pin @return array<string, mixed> */
function publicPin(array $pin): array
{
    unset($pin['author_id']);
    if (isset($pin['messages']) && is_array($pin['messages'])) {
        $pin['messages'] = array_map(
            static fn (mixed $message): mixed => is_array($message) ? publicMessage($message) : $message,
            $pin['messages']
        );
    }
    return $pin;
}

try {
    $config = ReviewLayer\loadConfig();
    $storage = ReviewLayer\createStorage($config);
    $security = new Security($config);
    $usageLimits = new UsageLimits($storage, $config);
    $action = isset($_GET['action']) && is_string($_GET['action']) ? $_GET['action'] : 'health';

    if ($action === 'health') {
        ReviewLayer\requireMethod('GET');
        ReviewLayer\respond(true, [
            'version' => ReviewLayer\REVIEWLAYER_VERSION,
            'status' => 'ok',
            'storage' => $storage->mode(),
            'php_version' => PHP_VERSION,
        ], null);
    }

    if ($action === 'bootstrap') {
        ReviewLayer\requireMethod('GET');
        ReviewLayer\respond(true, [
            'version' => ReviewLayer\REVIEWLAYER_VERSION,
            'storage' => $storage->mode(),
            'csrf_token' => $security->csrfToken(),
            'allow_guests' => (bool) $config['ALLOW_GUESTS'],
            'admin_code_configured' => $security->adminCodeConfigured(),
            'admin_actions_enabled' => $security->adminActionsEnabled(),
            'breakpoints' => [
                'mobile' => (int) $config['MOBILE_BREAKPOINT'],
                'desktop' => (int) $config['DESKTOP_BREAKPOINT'],
            ],
        ], null);
    }

    if ($action !== 'admin-clear') {
        $security->assertProjectAccess();
    }

    if ($action === 'list-pins') {
        ReviewLayer\requireMethod('GET');
        $projectKey = Validation::projectKey($_GET['project_key'] ?? null);
        $context = Validation::pageContext($_GET['page_key'] ?? null, $_GET['page_url'] ?? null);
        ReviewLayer\respond(true, ['pins' => array_map('publicPin', $storage->listPins($projectKey, $context['page_key']))], null);
    }

    if ($action === 'list-project-pins') {
        ReviewLayer\requireMethod('GET');
        $projectKey = Validation::projectKey($_GET['project_key'] ?? null);
        ReviewLayer\respond(true, ['pins' => $storage->listProjectPins($projectKey)], null);
    }

    if ($action === 'get-pin') {
        ReviewLayer\requireMethod('GET');
        $projectKey = Validation::projectKey($_GET['project_key'] ?? null);
        $id = Validation::uuid($_GET['id'] ?? null);
        $pin = $storage->getPin($id, $projectKey);
        if ($pin === null) {
            ReviewLayer\respond(false, null, ['code' => 'NOT_FOUND', 'message' => 'Pin not found.'], 404);
        }
        ReviewLayer\respond(true, ['pin' => publicPin($pin)], null);
    }

    ReviewLayer\requireMethod('POST', 'PATCH', 'DELETE');
    $security->assertCsrf();
    $security->rateLimit($action);
    $body = ReviewLayer\jsonBody();

    if ($action === 'create-pin') {
        ReviewLayer\requireMethod('POST');
        $projectKey = Validation::projectKey($body['project_key'] ?? null);
        $context = Validation::pageContext($body['page_key'] ?? null, $body['page_url'] ?? null);
        $authorId = Validation::uuid($body['author_id'] ?? null, 'author_id');
        $usageLimits->assertCanCreatePin($projectKey, $authorId);
        $authorName = Validation::string($body['author_name'] ?? null, 'author_name', 1, (int) $config['MAX_NAME_LENGTH']);
        $messageText = Validation::string($body['message'] ?? null, 'message', 1, (int) $config['MAX_MESSAGE_LENGTH']);
        $fingerprint = Validation::object($body['target_fingerprint'] ?? null, 'target_fingerprint');
        $anchor = sanitizeAnchor(Validation::object($body['anchor'] ?? null, 'anchor'));
        $viewport = sanitizeViewport(Validation::object($body['viewport'] ?? null, 'viewport'), $config);
        $browser = sanitizeBrowser(Validation::object($body['browser'] ?? null, 'browser'));
        $now = gmdate('c');
        $pinId = ReviewLayer\uuidV4();
        $pin = [
            'id' => $pinId,
            'project_key' => $projectKey,
            'page_key' => $context['page_key'],
            'page_url' => $context['page_url'],
            'status' => 'open',
            'author_id' => $authorId,
            'author_name' => $authorName,
            'target_selector' => Validation::string($body['target_selector'] ?? null, 'target_selector', 1, 2048),
            'target_fingerprint' => $fingerprint,
            'anchor' => $anchor,
            'viewport' => $viewport,
            'browser' => $browser,
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ];
        $message = [
            'id' => ReviewLayer\uuidV4(),
            'pin_id' => $pinId,
            'author_id' => $authorId,
            'author_name' => $authorName,
            'message' => $messageText,
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ];
        ReviewLayer\respond(true, ['pin' => publicPin($storage->createPin($pin, $message))], null, 201);
    }

    if ($action === 'add-message') {
        ReviewLayer\requireMethod('POST');
        $projectKey = Validation::projectKey($body['project_key'] ?? null);
        $pinId = Validation::uuid($_GET['id'] ?? null);
        $usageLimits->assertCanAddMessage($pinId);
        $now = gmdate('c');
        $message = [
            'id' => ReviewLayer\uuidV4(),
            'pin_id' => $pinId,
            'project_key' => $projectKey,
            'author_id' => Validation::uuid($body['author_id'] ?? null, 'author_id'),
            'author_name' => Validation::string($body['author_name'] ?? null, 'author_name', 1, (int) $config['MAX_NAME_LENGTH']),
            'message' => Validation::string($body['message'] ?? null, 'message', 1, (int) $config['MAX_MESSAGE_LENGTH']),
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ];
        ReviewLayer\respond(true, ['message' => publicMessage($storage->addMessage($message))], null, 201);
    }

    if ($action === 'update-status') {
        ReviewLayer\requireMethod('PATCH', 'POST');
        $projectKey = Validation::projectKey($body['project_key'] ?? null);
        $id = Validation::uuid($_GET['id'] ?? null);
        Validation::uuid($body['author_id'] ?? null, 'author_id');
        $updated = $storage->updatePinStatus($id, $projectKey, Validation::status($body['status'] ?? null), gmdate('c'));
        if (!$updated) {
            ReviewLayer\respond(false, null, ['code' => 'NOT_FOUND', 'message' => 'Pin not found.'], 404);
        }
        ReviewLayer\respond(true, ['updated' => true], null);
    }

    if ($action === 'delete-pin') {
        ReviewLayer\requireMethod('DELETE', 'POST');
        $projectKey = Validation::projectKey($body['project_key'] ?? null);
        $id = Validation::uuid($_GET['id'] ?? null);
        $authorId = Validation::uuid($body['author_id'] ?? null, 'author_id');
        $adminCode = isset($body['admin_code']) && is_string($body['admin_code']) ? $body['admin_code'] : '';
        $pin = $storage->getPin($id, $projectKey);
        if ($pin === null) {
            ReviewLayer\respond(false, null, ['code' => 'NOT_FOUND', 'message' => 'Pin not found.'], 404);
        }
        if (!$security->canDeletePin($pin, $authorId, $adminCode)) {
            throw new SecurityException('ACCESS_DENIED', 'Pin deletion denied.');
        }
        $storage->softDeletePin($id, $projectKey, gmdate('c'));
        ReviewLayer\respond(true, ['deleted' => true], null);
    }

    if ($action === 'delete-message') {
        ReviewLayer\requireMethod('DELETE', 'POST');
        $projectKey = Validation::projectKey($body['project_key'] ?? null);
        $id = Validation::uuid($_GET['id'] ?? null);
        $authorId = Validation::uuid($body['author_id'] ?? null, 'author_id');
        $adminCode = isset($body['admin_code']) && is_string($body['admin_code']) ? $body['admin_code'] : '';
        $message = $storage->getMessage($id, $projectKey);
        if ($message === null) {
            ReviewLayer\respond(false, null, ['code' => 'NOT_FOUND', 'message' => 'Message not found.'], 404);
        }
        if (!$security->canDeleteMessage($message, $authorId, $adminCode)) {
            throw new SecurityException('ACCESS_DENIED', 'Message deletion denied.');
        }
        $storage->softDeleteMessage($id, $projectKey, gmdate('c'));
        ReviewLayer\respond(true, ['deleted' => true], null);
    }

    if ($action === 'create-backup') {
        ReviewLayer\requireMethod('POST');
        $adminCode = $security->adminCodeConfigured()
            ? Validation::string($body['admin_code'] ?? null, 'admin_code', 1, 200)
            : '';
        $security->assertAdmin($adminCode);
        $projectKey = Validation::projectKey($body['project_key'] ?? null);
        try {
            $backupFile = (new BackupService(dirname(__DIR__) . '/data/backups', (int) $config['MAX_BACKUPS']))->create($storage);
        } catch (Throwable $error) {
            throw new BackupException('Manual backup creation failed.', 0, $error);
        }
        $storage->logAdmin([
            'id' => ReviewLayer\uuidV4(),
            'action' => 'backup',
            'scope' => 'all_projects',
            'mode' => 'export',
            'project_key' => $projectKey,
            'page_key' => '',
            'result' => ['backup_file' => $backupFile],
            'actor_hash' => $security->actorHash(),
            'created_at' => gmdate('c'),
        ]);
        ReviewLayer\respond(true, ['backup_file' => $backupFile], null, 201);
    }

    if ($action === 'admin-clear') {
        ReviewLayer\requireMethod('POST');
        $scope = Validation::oneOf($body['scope'] ?? null, 'scope', ['current_page', 'current_project', 'resolved_in_project', 'all_projects']);
        $mode = Validation::oneOf($body['mode'] ?? null, 'mode', ['soft', 'purge']);
        if ($scope === 'all_projects' && $mode !== 'purge') {
            throw new InvalidArgumentException('all_projects requires permanent purge.');
        }
        $confirmation = Validation::string($body['confirmation'] ?? null, 'confirmation', 1, 64);
        $expectedConfirmation = $scope === 'all_projects' ? 'DELETE ALL REVIEWLAYER DATA' : 'DELETE';
        if (!hash_equals($expectedConfirmation, $confirmation)) {
            throw new InvalidArgumentException('Confirmation phrase is invalid.');
        }
        $adminCode = $security->adminCodeConfigured()
            ? Validation::string($body['admin_code'] ?? null, 'admin_code', 1, 200)
            : '';
        $security->assertAdmin($adminCode);
        $projectKey = Validation::projectKey($body['project_key'] ?? null);
        $context = Validation::pageContext($body['page_key'] ?? null, $body['page_url'] ?? null);
        $service = new ClearService(
            $storage,
            new BackupService(dirname(__DIR__) . '/data/backups', (int) $config['MAX_BACKUPS']),
            $config
        );
        $result = $service->execute(
            $scope,
            $mode,
            $projectKey,
            $context['page_key'],
            ($body['continue_without_backup'] ?? false) === true,
            $security->actorHash()
        );
        ReviewLayer\respond(true, $result, null);
    }

    ReviewLayer\respond(false, null, ['code' => 'NOT_FOUND', 'message' => 'API action not found.'], 404);
} catch (SecurityException $error) {
    $status = $error->errorCode === 'RATE_LIMITED' ? 429 : 403;
    ReviewLayer\respond(false, null, ['code' => $error->errorCode, 'message' => $error->getMessage()], $status);
} catch (BackupException $error) {
    error_log('[ReviewLayer] Backup error: ' . $error->getMessage());
    ReviewLayer\respond(false, null, ['code' => 'BACKUP_FAILED', 'message' => 'Backup creation failed.'], 409);
} catch (StorageNotFoundException $error) {
    ReviewLayer\respond(false, null, ['code' => 'NOT_FOUND', 'message' => $error->getMessage()], 404);
} catch (UsageLimitException $error) {
    ReviewLayer\respond(false, null, ['code' => 'USAGE_LIMIT_REACHED', 'message' => $error->getMessage()], 429);
} catch (InvalidArgumentException | JsonException $error) {
    ReviewLayer\respond(false, null, ['code' => 'VALIDATION_ERROR', 'message' => $error->getMessage()], 422);
} catch (Throwable $error) {
    error_log('[ReviewLayer] ' . $error::class . ': ' . $error->getMessage());
    $configurationError = str_contains($error->getMessage(), 'config') || str_contains($error->getMessage(), 'STORAGE_MODE');
    ReviewLayer\respond(false, null, [
        'code' => $configurationError ? 'CONFIGURATION_ERROR' : 'STORAGE_ERROR',
        'message' => $configurationError ? 'ReviewLayer configuration is invalid.' : 'ReviewLayer storage operation failed.',
    ], 500);
}

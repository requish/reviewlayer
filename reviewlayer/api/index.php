<?php

declare(strict_types=1);

use ReviewLayer\BackupException;
use ReviewLayer\BackupService;
use ReviewLayer\ClearService;
use ReviewLayer\NotificationException;
use ReviewLayer\NotificationService;
use ReviewLayer\Security;
use ReviewLayer\SecurityException;
use ReviewLayer\StorageInterface;
use ReviewLayer\StorageNotFoundException;
use ReviewLayer\UsageLimitException;
use ReviewLayer\UsageLimits;
use ReviewLayer\Validation;

require_once __DIR__ . '/bootstrap.php';

/** @param array<string, mixed> $value @return array<string, mixed> */
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

    $output['fallback_ancestors'] = [];
    $fallbackAncestors = $value['fallback_ancestors'] ?? [];
    if (!is_array($fallbackAncestors)) {
        throw new InvalidArgumentException('anchor.fallback_ancestors is invalid.');
    }
    foreach (array_slice($fallbackAncestors, 0, 8) as $index => $fallback) {
        if (!is_array($fallback)) {
            throw new InvalidArgumentException('anchor.fallback_ancestors.' . $index . ' is invalid.');
        }
        $selector = Validation::string(
            $fallback['selector'] ?? null,
            'anchor.fallback_ancestors.' . $index . '.selector',
            1,
            2048
        );
        foreach (['offset_x', 'offset_y'] as $field) {
            if (!isset($fallback[$field]) || !is_numeric($fallback[$field]) || !is_finite((float) $fallback[$field])) {
                throw new InvalidArgumentException('anchor.fallback_ancestors.' . $index . '.' . $field . ' is invalid.');
            }
        }
        $sanitizedFallback = [
            'selector' => $selector,
            'offset_x' => (float) $fallback['offset_x'],
            'offset_y' => (float) $fallback['offset_y'],
        ];
        foreach (['relative_x', 'relative_y'] as $field) {
            if (!isset($fallback[$field])) {
                continue;
            }
            if (!is_numeric($fallback[$field]) || !is_finite((float) $fallback[$field]) || abs((float) $fallback[$field]) > 100) {
                throw new InvalidArgumentException('anchor.fallback_ancestors.' . $index . '.' . $field . ' is invalid.');
            }
            $sanitizedFallback[$field] = (float) $fallback[$field];
        }
        $output['fallback_ancestors'][] = $sanitizedFallback;
    }

    if (isset($value['interaction_state'])) {
        if (!is_string($value['interaction_state']) || $value['interaction_state'] !== 'hover') {
            throw new InvalidArgumentException('anchor.interaction_state is invalid.');
        }
        $output['interaction_state'] = 'hover';
    }
    if (isset($value['interaction_trigger'])) {
        $trigger = Validation::object($value['interaction_trigger'], 'anchor.interaction_trigger', 16384);
        $relative = [];
        foreach (['relative_x', 'relative_y'] as $field) {
            if (!isset($trigger[$field]) || !is_numeric($trigger[$field]) || !is_finite((float) $trigger[$field])) {
                throw new InvalidArgumentException('anchor.interaction_trigger.' . $field . ' is invalid.');
            }
            $relative[$field] = (float) $trigger[$field];
            if ($relative[$field] < 0 || $relative[$field] > 1) {
                throw new InvalidArgumentException('anchor.interaction_trigger.' . $field . ' is invalid.');
            }
        }
        $output['interaction_trigger'] = [
            'selector' => Validation::string($trigger['selector'] ?? null, 'anchor.interaction_trigger.selector', 1, 2048),
            'target_fingerprint' => Validation::object(
                $trigger['target_fingerprint'] ?? null,
                'anchor.interaction_trigger.target_fingerprint',
                8192
            ),
            'relative_x' => $relative['relative_x'],
            'relative_y' => $relative['relative_y'],
        ];
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

/** @return array{author_id:string,author_name:string} */
function canonicalAuthorIdentity(
    NotificationService $notifications,
    StorageInterface $storage,
    string $projectKey,
    string $authorId,
    string $authorName
): array {
    $canonicalAuthorId = $notifications->resolveAuthorId($projectKey, $authorId);
    if (hash_equals($canonicalAuthorId, $authorId)) {
        return ['author_id' => $authorId, 'author_name' => $authorName];
    }
    foreach ($storage->listProjectUserRecords($projectKey) as $user) {
        if (hash_equals((string) ($user['author_id'] ?? ''), $canonicalAuthorId)) {
            return [
                'author_id' => $canonicalAuthorId,
                'author_name' => (string) ($user['author_name'] ?? $authorName),
            ];
        }
    }
    return ['author_id' => $authorId, 'author_name' => $authorName];
}

function respondVerificationPage(bool $success, string $language, bool $linked = false): never
{
    $polish = $language === 'pl';
    $title = $success
        ? ($polish ? 'Adres e-mail potwierdzony' : 'Email address confirmed')
        : ($polish ? 'Nie udało się potwierdzić adresu' : 'Email confirmation failed');
    $message = $success
        ? ($linked
            ? ($polish ? 'Urządzenie zostało połączone z Twoją istniejącą tożsamością komentującego. Możesz zamknąć tę kartę.' : 'This device was linked to your existing commenter identity. You can close this tab.')
            : ($polish ? 'Możesz zamknąć tę kartę i wrócić do ReviewLayer.' : 'You can close this tab and return to ReviewLayer.'))
        : ($polish ? 'Link jest nieprawidłowy, wygasł albo został już użyty.' : 'The link is invalid, expired, or has already been used.');
    http_response_code($success ? 200 : 400);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
    echo '<!doctype html><html lang="' . ($polish ? 'pl' : 'en') . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</title><style>html{color-scheme:light;font-family:system-ui,sans-serif;background:#f4f6f9;color:#18202d}body{min-height:100vh;display:grid;place-items:center;margin:0;padding:24px;box-sizing:border-box}.card{width:min(100%,480px);padding:32px;background:#fff;border:1px solid #d6dce6;border-radius:16px;box-shadow:0 18px 50px rgba(24,32,45,.14)}.mark{width:44px;height:44px;display:grid;place-items:center;margin-bottom:22px;color:#fff;font-size:24px;font-weight:700;background:' . ($success ? '#08775a' : '#8f1d15') . ';border-radius:50%}h1{margin:0 0 10px;font-size:26px;line-height:1.2}p{margin:0;color:#667085;line-height:1.6}</style></head><body><main class="card"><div class="mark" aria-hidden="true">' . ($success ? '✓' : '!') . '</div><h1>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h1><p>' . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p></main></body></html>';
    exit;
}

try {
    $config = ReviewLayer\loadConfig();
    $storage = ReviewLayer\createStorage($config);
    $security = new Security($config);
    $usageLimits = new UsageLimits($storage, $config);
    $notifications = new NotificationService(dirname(__DIR__) . '/data', $config);
    try {
        $notifications->synchronizeLinkedAuthors($storage);
    } catch (Throwable $error) {
        error_log('[ReviewLayer] Deferred author synchronization: ' . $error->getMessage());
    }
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
            'notifications_available' => $notifications->isAvailable(),
            'breakpoints' => [
                'mobile' => (int) $config['MOBILE_BREAKPOINT'],
                'desktop' => (int) $config['DESKTOP_BREAKPOINT'],
            ],
        ], null);
    }

    if ($action === 'verify-email') {
        ReviewLayer\requireMethod('GET');
        $language = 'en';
        try {
            $result = $notifications->verify($storage, Validation::string($_GET['token'] ?? null, 'token', 80, 100));
            $language = (string) ($result['language'] ?? 'en');
            respondVerificationPage(true, $language, (bool) ($result['linked'] ?? false));
        } catch (Throwable) {
            $requestedLanguage = isset($_GET['lang']) && $_GET['lang'] === 'pl' ? 'pl' : 'en';
            respondVerificationPage(false, $requestedLanguage);
        }
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

    if ($action === 'list-project-users') {
        ReviewLayer\requireMethod('GET');
        $projectKey = Validation::projectKey($_GET['project_key'] ?? null);
        ReviewLayer\respond(true, ['users' => $storage->listProjectUsers($projectKey)], null);
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

    if ($action === 'notification-settings') {
        ReviewLayer\requireMethod('POST');
        $settings = $notifications->settings(
            $storage,
            Validation::projectKey($body['project_key'] ?? null),
            Validation::uuid($body['author_id'] ?? null, 'author_id'),
            Validation::browserSecret($body['author_secret'] ?? null),
            Validation::oneOf($body['language'] ?? null, 'language', ['pl', 'en'])
        );
        ReviewLayer\respond(true, ['settings' => $settings], null);
    }

    if ($action === 'get-user-profile') {
        ReviewLayer\requireMethod('POST');
        $projectKey = Validation::projectKey($body['project_key'] ?? null);
        $authorId = $notifications->resolveAuthorId(
            $projectKey,
            Validation::uuid($body['author_id'] ?? null, 'author_id')
        );
        $profile = null;
        foreach ($storage->listProjectUserRecords($projectKey) as $user) {
            if (!hash_equals((string) ($user['author_id'] ?? ''), $authorId)) continue;
            $profile = [
                'author_name' => (string) ($user['author_name'] ?? ''),
                'color_index' => (int) ($user['color_index'] ?? 1),
                'role_key' => (string) ($user['role_key'] ?? 'unassigned'),
            ];
            break;
        }
        ReviewLayer\respond(true, ['profile' => $profile, 'canonical_author_id' => $authorId], null);
    }

    if ($action === 'update-user-role') {
        ReviewLayer\requireMethod('POST', 'PATCH');
        $projectKey = Validation::projectKey($body['project_key'] ?? null);
        $authorId = $notifications->resolveAuthorId(
            $projectKey,
            Validation::uuid($body['author_id'] ?? null, 'author_id')
        );
        $roleKey = Validation::roleKey($body['role_key'] ?? null);
        $updated = $storage->updateProjectUserRole($projectKey, $authorId, $roleKey, gmdate('c'));
        ReviewLayer\respond(true, ['updated' => $updated, 'role_key' => $roleKey], null);
    }

    if ($action === 'request-email-verification') {
        ReviewLayer\requireMethod('POST');
        $settings = $notifications->requestVerification(
            $storage,
            Validation::projectKey($body['project_key'] ?? null),
            Validation::uuid($body['author_id'] ?? null, 'author_id'),
            Validation::browserSecret($body['author_secret'] ?? null),
            Validation::oneOf($body['language'] ?? null, 'language', ['pl', 'en']),
            Validation::email($body['email'] ?? null)
        );
        ReviewLayer\respond(true, ['settings' => $settings], null, 201);
    }

    if ($action === 'remove-notification-email') {
        ReviewLayer\requireMethod('POST', 'DELETE');
        $settings = $notifications->removeEmail(
            $storage,
            Validation::projectKey($body['project_key'] ?? null),
            Validation::uuid($body['author_id'] ?? null, 'author_id'),
            Validation::browserSecret($body['author_secret'] ?? null),
            Validation::oneOf($body['language'] ?? null, 'language', ['pl', 'en'])
        );
        ReviewLayer\respond(true, ['settings' => $settings], null);
    }

    if ($action === 'list-notification-recipients') {
        ReviewLayer\requireMethod('POST');
        $recipients = $notifications->recipients(
            $storage,
            Validation::projectKey($body['project_key'] ?? null),
            Validation::uuid($body['author_id'] ?? null, 'author_id'),
            Validation::browserSecret($body['author_secret'] ?? null),
            Validation::oneOf($body['language'] ?? null, 'language', ['pl', 'en'])
        );
        ReviewLayer\respond(true, ['recipients' => $recipients], null);
    }

    if ($action === 'send-notification') {
        ReviewLayer\requireMethod('POST');
        $notifications->send(
            $storage,
            Validation::projectKey($body['project_key'] ?? null),
            Validation::uuid($body['author_id'] ?? null, 'author_id'),
            Validation::browserSecret($body['author_secret'] ?? null),
            Validation::oneOf($body['language'] ?? null, 'language', ['pl', 'en']),
            Validation::uuid($body['recipient_id'] ?? null, 'recipient_id'),
            Validation::pageUrl($body['page_url'] ?? null)
        );
        ReviewLayer\respond(true, ['sent' => true], null);
    }

    if ($action === 'send-role-notification') {
        ReviewLayer\requireMethod('POST');
        $sent = $notifications->sendRole(
            $storage,
            Validation::projectKey($body['project_key'] ?? null),
            Validation::uuid($body['author_id'] ?? null, 'author_id'),
            Validation::browserSecret($body['author_secret'] ?? null),
            Validation::oneOf($body['language'] ?? null, 'language', ['pl', 'en']),
            Validation::audienceRole($body['audience_role'] ?? null),
            Validation::pageUrl($body['page_url'] ?? null)
        );
        ReviewLayer\respond(true, ['sent' => $sent], null);
    }

    if ($action === 'create-pin') {
        ReviewLayer\requireMethod('POST');
        $projectKey = Validation::projectKey($body['project_key'] ?? null);
        $context = Validation::pageContext($body['page_key'] ?? null, $body['page_url'] ?? null);
        $identity = canonicalAuthorIdentity(
            $notifications,
            $storage,
            $projectKey,
            Validation::uuid($body['author_id'] ?? null, 'author_id'),
            Validation::string($body['author_name'] ?? null, 'author_name', 1, (int) $config['MAX_NAME_LENGTH'])
        );
        $authorId = $identity['author_id'];
        $authorName = $identity['author_name'];
        $usageLimits->assertCanCreatePin($projectKey, $authorId);
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
            'author_role_key' => Validation::roleKey((string) ($body['role_key'] ?? 'unassigned')),
            'audience_role' => Validation::audienceRole((string) ($body['audience_role'] ?? 'all')),
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
        $identity = canonicalAuthorIdentity(
            $notifications,
            $storage,
            $projectKey,
            Validation::uuid($body['author_id'] ?? null, 'author_id'),
            Validation::string($body['author_name'] ?? null, 'author_name', 1, (int) $config['MAX_NAME_LENGTH'])
        );
        $now = gmdate('c');
        $message = [
            'id' => ReviewLayer\uuidV4(),
            'pin_id' => $pinId,
            'project_key' => $projectKey,
            'author_id' => $identity['author_id'],
            'author_name' => $identity['author_name'],
            'author_role_key' => Validation::roleKey((string) ($body['role_key'] ?? 'unassigned')),
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
        $authorId = $notifications->resolveAuthorId(
            $projectKey,
            Validation::uuid($body['author_id'] ?? null, 'author_id')
        );
        $updated = $storage->updatePinStatus(
            $id,
            $projectKey,
            Validation::status($body['status'] ?? null),
            $authorId,
            gmdate('c')
        );
        if (!$updated) {
            ReviewLayer\respond(false, null, ['code' => 'NOT_FOUND', 'message' => 'Pin not found.'], 404);
        }
        ReviewLayer\respond(true, ['updated' => true], null);
    }

    if ($action === 'update-pin-audience') {
        ReviewLayer\requireMethod('PATCH', 'POST');
        $projectKey = Validation::projectKey($body['project_key'] ?? null);
        $id = Validation::uuid($_GET['id'] ?? null);
        $authorId = $notifications->resolveAuthorId(
            $projectKey,
            Validation::uuid($body['author_id'] ?? null, 'author_id')
        );
        $knownAuthor = false;
        foreach ($storage->listProjectUserRecords($projectKey) as $user) {
            if (hash_equals((string) ($user['author_id'] ?? ''), $authorId)) {
                $knownAuthor = true;
                break;
            }
        }
        if (!$knownAuthor) {
            throw new SecurityException('ACCESS_DENIED', 'Only a project commenter can change the pin audience.');
        }
        $audienceRole = Validation::audienceRole($body['audience_role'] ?? null);
        if (!$storage->updatePinAudience($id, $projectKey, $audienceRole, gmdate('c'))) {
            ReviewLayer\respond(false, null, ['code' => 'NOT_FOUND', 'message' => 'Pin not found.'], 404);
        }
        ReviewLayer\respond(true, ['updated' => true, 'audience_role' => $audienceRole], null);
    }

    if ($action === 'delete-pin') {
        ReviewLayer\requireMethod('DELETE', 'POST');
        $projectKey = Validation::projectKey($body['project_key'] ?? null);
        $id = Validation::uuid($_GET['id'] ?? null);
        $authorId = $notifications->resolveAuthorId(
            $projectKey,
            Validation::uuid($body['author_id'] ?? null, 'author_id')
        );
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
        $authorId = $notifications->resolveAuthorId(
            $projectKey,
            Validation::uuid($body['author_id'] ?? null, 'author_id')
        );
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
        try {
            $notifications->pruneOrphanedProfiles($storage);
        } catch (Throwable $notificationCleanupError) {
            error_log('[ReviewLayer] Notification cleanup error: ' . $notificationCleanupError->getMessage());
        }
        ReviewLayer\respond(true, $result, null);
    }

    ReviewLayer\respond(false, null, ['code' => 'NOT_FOUND', 'message' => 'API action not found.'], 404);
} catch (SecurityException $error) {
    $status = $error->errorCode === 'RATE_LIMITED' ? 429 : 403;
    ReviewLayer\respond(false, null, ['code' => $error->errorCode, 'message' => $error->getMessage()], $status);
} catch (NotificationException $error) {
    $status = match ($error->errorCode) {
        'NOTIFICATION_IDENTITY_DENIED' => 403,
        'NOTIFICATION_RATE_LIMITED' => 429,
        'NOTIFICATIONS_UNAVAILABLE' => 503,
        'MAIL_FAILED' => 502,
        'VALIDATION_ERROR' => 422,
        default => 409,
    };
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

<?php

declare(strict_types=1);

namespace ReviewLayer;

use RuntimeException;

final class Security
{
    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config)
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_name('REVIEWLAYERSESSID');
            session_set_cookie_params([
                'httponly' => true,
                'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                'samesite' => 'Lax',
                'path' => '/',
            ]);
            session_start();
        }
    }

    public function csrfToken(): string
    {
        if (!isset($_SESSION['reviewlayer_csrf']) || !is_string($_SESSION['reviewlayer_csrf'])) {
            $_SESSION['reviewlayer_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['reviewlayer_csrf'];
    }

    public function assertCsrf(): void
    {
        $provided = $_SERVER['HTTP_X_REVIEWLAYER_CSRF'] ?? '';
        if (!is_string($provided) || !hash_equals($this->csrfToken(), $provided)) {
            throw new SecurityException('CSRF_ERROR', 'The CSRF token is invalid.');
        }
        $this->assertSameOrigin();
    }

    public function assertProjectAccess(): void
    {
        if ((bool) $this->config['ALLOW_GUESTS']) {
            return;
        }
        $provided = $_SERVER['HTTP_X_REVIEWLAYER_ACCESS'] ?? '';
        if (!is_string($provided) || !$this->verifyConfiguredCode('PROJECT_ACCESS_CODE', 'PROJECT_ACCESS_CODE_HASH', $provided)) {
            throw new SecurityException('ACCESS_DENIED', 'Project access denied.');
        }
    }

    public function assertAdmin(string $code): void
    {
        if (!$this->adminCodeConfigured()) {
            if ($this->adminActionsEnabled()) {
                return;
            }
            throw new SecurityException('ACCESS_DENIED', 'Administrator actions are disabled until an administrator code is configured.');
        }
        if (!$this->verifyConfiguredCode('ADMIN_ACCESS_CODE', 'ADMIN_ACCESS_CODE_HASH', $code)) {
            throw new SecurityException('ACCESS_DENIED', 'Administrator access denied.');
        }
    }

    public function adminActionsEnabled(): bool
    {
        return $this->adminCodeConfigured() || (bool) ($this->config['ALLOW_ADMIN_WITHOUT_CODE'] ?? true);
    }

    public function adminCodeConfigured(): bool
    {
        foreach (['ADMIN_ACCESS_CODE', 'ADMIN_ACCESS_CODE_HASH'] as $key) {
            if (isset($this->config[$key]) && is_string($this->config[$key]) && $this->config[$key] !== '') {
                return true;
            }
        }
        return false;
    }

    public function canDeletePin(array $pin, string $authorId, string $adminCode): bool
    {
        if (!$this->adminCodeConfigured()) {
            if ((bool) ($this->config['ALLOW_ADMIN_WITHOUT_CODE'] ?? true)) {
                return true;
            }
            return (bool) $this->config['ALLOW_AUTHOR_DELETE_OWN_PINS']
                && isset($pin['author_id'])
                && hash_equals((string) $pin['author_id'], $authorId);
        }
        if ($adminCode !== '' && $this->isAdmin($adminCode)) {
            return true;
        }
        return (bool) $this->config['ALLOW_AUTHOR_DELETE_OWN_PINS']
            && isset($pin['author_id'])
            && hash_equals((string) $pin['author_id'], $authorId);
    }

    public function canDeleteMessage(array $message, string $authorId, string $adminCode): bool
    {
        if ($adminCode !== '' && $this->isAdmin($adminCode)) {
            return true;
        }
        return (bool) $this->config['ALLOW_AUTHOR_DELETE_OWN_MESSAGES']
            && isset($message['author_id'])
            && hash_equals((string) $message['author_id'], $authorId);
    }

    public function rateLimit(string $bucket): void
    {
        $now = time();
        $window = (int) $this->config['RATE_LIMIT_WINDOW_SECONDS'];
        $limit = (int) $this->config['RATE_LIMIT_REQUESTS'];
        if ($limit <= 0 || $window <= 0) {
            return;
        }
        if ((bool) ($this->config['PERSISTENT_RATE_LIMIT'] ?? false)) {
            $this->persistentRateLimit($bucket, $now, $window, $limit);
            return;
        }
        $key = 'reviewlayer_rate_' . hash('sha256', $bucket);
        $attempts = $_SESSION[$key] ?? [];
        if (!is_array($attempts)) {
            $attempts = [];
        }
        $attempts = array_values(array_filter($attempts, static fn (mixed $timestamp): bool => is_int($timestamp) && $timestamp > $now - $window));
        if (count($attempts) >= $limit) {
            throw new SecurityException('RATE_LIMITED', 'Too many requests.');
        }
        $attempts[] = $now;
        $_SESSION[$key] = $attempts;
    }

    private function persistentRateLimit(string $bucket, int $now, int $window, int $limit): void
    {
        $path = dirname(__DIR__) . '/data/.rate-limits.json';
        $handle = fopen($path, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Unable to open the rate-limit store.');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Unable to lock the rate-limit store.');
            }
            rewind($handle);
            $raw = stream_get_contents($handle);
            $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
            if (!is_array($data)) {
                $data = [];
            }

            $cutoff = $now - $window;
            foreach ($data as $key => $timestamps) {
                if (!is_array($timestamps)) {
                    unset($data[$key]);
                    continue;
                }
                $timestamps = array_values(array_filter(
                    $timestamps,
                    static fn (mixed $timestamp): bool => is_int($timestamp) && $timestamp > $cutoff
                ));
                if ($timestamps === []) {
                    unset($data[$key]);
                } else {
                    $data[$key] = $timestamps;
                }
            }

            $remoteAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
            $key = hash('sha256', $remoteAddress . '|' . $bucket);
            $attempts = isset($data[$key]) && is_array($data[$key]) ? $data[$key] : [];
            if (count($attempts) >= $limit) {
                throw new SecurityException('RATE_LIMITED', 'Too many requests.');
            }
            $attempts[] = $now;
            $data[$key] = $attempts;

            $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            rewind($handle);
            if (!ftruncate($handle, 0) || fwrite($handle, $encoded) !== strlen($encoded)) {
                throw new RuntimeException('Unable to update the rate-limit store.');
            }
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }

    public function actorHash(): string
    {
        $source = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        return hash('sha256', $source);
    }

    private function isAdmin(string $code): bool
    {
        return $this->verifyConfiguredCode('ADMIN_ACCESS_CODE', 'ADMIN_ACCESS_CODE_HASH', $code);
    }

    private function verifyConfiguredCode(string $plainKey, string $hashKey, string $provided): bool
    {
        $hash = isset($this->config[$hashKey]) && is_string($this->config[$hashKey])
            ? $this->config[$hashKey]
            : '';
        if ($hash !== '') {
            return password_verify($provided, $hash);
        }

        $plain = isset($this->config[$plainKey]) && is_string($this->config[$plainKey])
            ? $this->config[$plainKey]
            : '';
        return $plain !== '' && hash_equals($plain, $provided);
    }

    private function assertSameOrigin(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if (!is_string($origin) || $origin === '') {
            return;
        }
        $originParts = parse_url($origin);
        $requestHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $originHost = strtolower((string) ($originParts['host'] ?? ''));
        $originPort = isset($originParts['port']) ? ':' . (int) $originParts['port'] : '';
        if ($originHost === '' || !hash_equals($requestHost, $originHost . $originPort)) {
            throw new SecurityException('CSRF_ERROR', 'Cross-origin modification denied.');
        }
    }
}

final class SecurityException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}

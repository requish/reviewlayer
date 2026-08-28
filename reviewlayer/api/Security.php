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
            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');
            session_name('REVIEWLAYERSESSID');
            session_set_cookie_params([
                'httponly' => true,
                'secure' => (bool) ($this->config['FORCE_SECURE_SESSION_COOKIE'] ?? false)
                    || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
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
            $this->rateLimit('project-access-failure', 'ACCESS_RATE_LIMIT_REQUESTS', 'ACCESS_RATE_LIMIT_WINDOW_SECONDS');
            throw new SecurityException('ACCESS_DENIED', 'Project access denied.');
        }
    }

    public function assertAdmin(string $code): void
    {
        if (!$this->adminCodeConfigured()) {
            throw new SecurityException('ACCESS_DENIED', 'Administrator actions are disabled until an administrator code is configured.');
        }
        if (!$this->verifyConfiguredCode('ADMIN_ACCESS_CODE', 'ADMIN_ACCESS_CODE_HASH', $code)) {
            throw new SecurityException('ACCESS_DENIED', 'Administrator access denied.');
        }
    }

    public function adminActionsEnabled(): bool
    {
        return $this->adminCodeConfigured();
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

    public function rateLimit(
        string $bucket,
        string $limitKey = 'RATE_LIMIT_REQUESTS',
        string $windowKey = 'RATE_LIMIT_WINDOW_SECONDS'
    ): void
    {
        $now = time();
        $window = (int) ($this->config[$windowKey] ?? 0);
        $limit = (int) ($this->config[$limitKey] ?? 0);
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
        $configuredDirectory = trim((string) ($this->config['DATA_DIRECTORY'] ?? ''));
        $dataDirectory = $configuredDirectory !== '' ? rtrim($configuredDirectory, "\\/ ") : dirname(__DIR__) . '/data';
        $path = $dataDirectory . '/.rate-limits.json';
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
            $data = [];
            if (is_string($raw) && $raw !== '') {
                try {
                    $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
                } catch (\JsonException $error) {
                    throw new RuntimeException('The rate-limit store is corrupt.', 0, $error);
                }
                if (!is_array($decoded) || array_is_list($decoded)) {
                    throw new RuntimeException('The rate-limit store is invalid.');
                }
                $data = $decoded;
            }

            foreach ($data as $storedKey => $entry) {
                if (is_array($entry) && array_is_list($entry)) {
                    $entry = ['window' => $window, 'timestamps' => $entry];
                }
                if (!is_array($entry) || !isset($entry['timestamps']) || !is_array($entry['timestamps'])) {
                    throw new RuntimeException('The rate-limit store contains an invalid bucket.');
                }
                $entryWindow = max(1, min(604800, (int) ($entry['window'] ?? $window)));
                $cutoff = $now - $entryWindow;
                $timestamps = array_values(array_filter(
                    $entry['timestamps'],
                    static fn (mixed $timestamp): bool => is_int($timestamp) && $timestamp > $cutoff
                ));
                if ($timestamps === []) {
                    unset($data[$storedKey]);
                } else {
                    $data[$storedKey] = ['window' => $entryWindow, 'timestamps' => $timestamps];
                }
            }

            $remoteAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
            $key = hash('sha256', $remoteAddress . '|' . $bucket);
            $maxBuckets = (int) ($this->config['RATE_LIMIT_MAX_BUCKETS'] ?? 5000);
            $maxBuckets = $maxBuckets > 0 ? $maxBuckets : 5000;
            if (!isset($data[$key]) && count($data) >= $maxBuckets) {
                throw new SecurityException('RATE_LIMITED', 'Too many requests.');
            }
            $attempts = isset($data[$key]['timestamps']) && is_array($data[$key]['timestamps'])
                ? $data[$key]['timestamps']
                : [];
            if (count($attempts) >= $limit) {
                throw new SecurityException('RATE_LIMITED', 'Too many requests.');
            }
            $attempts[] = $now;
            $data[$key] = ['window' => $window, 'timestamps' => $attempts];

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

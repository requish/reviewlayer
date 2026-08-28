<?php

declare(strict_types=1);

namespace ReviewLayer;

use InvalidArgumentException;

final class Validation
{
    /** @var list<string> */
    private static array $allowedProjectKeys = [];

    private const REVIEWLAYER_PARAMS = [
        'reviewlayer',
        'reviewlayer_action',
        'reviewlayer_lang',
        'reviewlayer_debug',
        'reviewlayer_token',
    ];

    public static function string(mixed $value, string $field, int $min, int $max): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException($field . ' must be a string.');
        }
        $value = trim($value);
        $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
        if ($length < $min || $length > $max) {
            throw new InvalidArgumentException($field . ' has an invalid length.');
        }
        return $value;
    }

    public static function projectKey(mixed $value): string
    {
        $value = self::string($value, 'project_key', 1, 64);
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,63}$/D', $value) !== 1) {
            throw new InvalidArgumentException('project_key has an invalid format.');
        }
        if (self::$allowedProjectKeys !== [] && !in_array($value, self::$allowedProjectKeys, true)) {
            throw new InvalidArgumentException('project_key is not allowed.');
        }
        return $value;
    }

    /** @param list<string> $allowedProjectKeys */
    public static function configureAllowedProjectKeys(array $allowedProjectKeys): void
    {
        self::$allowedProjectKeys = array_values(array_unique($allowedProjectKeys));
    }

    /** @param array<string, mixed> $config */
    public static function projectKeyForConfig(mixed $value, array $config): string
    {
        $projectKey = self::projectKey($value);
        $allowed = $config['ALLOWED_PROJECT_KEYS'] ?? [];
        if (is_array($allowed) && $allowed !== [] && !in_array($projectKey, $allowed, true)) {
            throw new InvalidArgumentException('project_key is not allowed.');
        }
        return $projectKey;
    }

    public static function uuid(mixed $value, string $field = 'id'): string
    {
        $value = self::string($value, $field, 36, 36);
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di', $value) !== 1) {
            throw new InvalidArgumentException($field . ' must be a UUID v4.');
        }
        return strtolower($value);
    }

    public static function browserSecret(mixed $value): string
    {
        $value = self::string($value, 'author_secret', 64, 64);
        if (preg_match('/^[0-9a-f]{64}$/D', $value) !== 1) {
            throw new InvalidArgumentException('author_secret has an invalid format.');
        }
        return $value;
    }

    public static function email(mixed $value): string
    {
        $value = strtolower(self::string($value, 'email', 3, 254));
        if (preg_match('/[\r\n]/', $value) === 1 || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('email is invalid.');
        }
        return $value;
    }

    public static function pageUrl(mixed $value): string
    {
        $url = self::string($value, 'page_url', 8, 4096);
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException('page_url is invalid.');
        }
        if (!in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)) {
            throw new InvalidArgumentException('page_url must use HTTP or HTTPS.');
        }
        if (isset($parts['user']) || isset($parts['pass']) || preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            throw new InvalidArgumentException('page_url contains unsupported authority data.');
        }
        return $url;
    }

    /** @param array<string, mixed> $config */
    public static function pageUrlForRequest(mixed $value, array $config): string
    {
        $url = self::pageUrl($value);
        if (!(bool) ($config['REQUIRE_SAME_HOST_PAGE_URL'] ?? false)) {
            return $url;
        }
        $parts = parse_url($url);
        $pageHost = is_array($parts) ? strtolower(rtrim((string) ($parts['host'] ?? ''), '.')) : '';
        $trustedHosts = self::trustedPageHosts($config);
        if ($trustedHosts === []) {
            return self::relativePageReference($url);
        }
        if ($pageHost === '' || !in_array($pageHost, $trustedHosts, true)) {
            throw new InvalidArgumentException('page_url must use a trusted page host.');
        }
        return $url;
    }

    /** @param array<string, mixed> $config */
    public static function assertTrustedRequestHost(array $config): void
    {
        $trustedHosts = self::trustedPageHosts($config);
        if ($trustedHosts === []) {
            return;
        }
        $requestHost = self::requestHost();
        if ($requestHost === '' || !in_array($requestHost, $trustedHosts, true)) {
            throw new InvalidArgumentException('The request Host is not trusted.');
        }
    }

    /** @param array<string, mixed> $config @return list<string> */
    public static function trustedPageHosts(array $config): array
    {
        $configured = $config['ALLOWED_PAGE_HOSTS'] ?? [];
        if (!is_array($configured) || !array_is_list($configured)) {
            throw new InvalidArgumentException('ALLOWED_PAGE_HOSTS must be a list.');
        }
        $hosts = [];
        foreach ($configured as $value) {
            if (!is_string($value)) {
                throw new InvalidArgumentException('ALLOWED_PAGE_HOSTS contains an invalid host.');
            }
            $host = self::normalizeHost($value);
            if ($host === '') {
                throw new InvalidArgumentException('ALLOWED_PAGE_HOSTS contains an invalid host.');
            }
            $hosts[$host] = true;
        }

        $publicBase = trim((string) ($config['NOTIFICATION_PUBLIC_BASE_URL'] ?? ''));
        $baseParts = $publicBase !== '' ? parse_url($publicBase) : false;
        if (is_array($baseParts) && isset($baseParts['host'])) {
            $host = strtolower(rtrim((string) $baseParts['host'], '.'));
            if ($host !== '') {
                $hosts[$host] = true;
            }
        }
        return array_keys($hosts);
    }

    public static function canonicalPageKey(string $pageUrl): string
    {
        $parts = parse_url(self::pageUrl($pageUrl));
        if (!is_array($parts)) {
            throw new InvalidArgumentException('page_url is invalid.');
        }
        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);
        if (str_contains($host, ':') && !str_starts_with($host, '[')) {
            $host = '[' . $host . ']';
        }
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        $portPart = $port !== null && !(($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443))
            ? ':' . $port
            : '';
        $path = isset($parts['path']) && $parts['path'] !== '' ? (string) $parts['path'] : '/';
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        $pairs = [];
        foreach (explode('&', (string) ($parts['query'] ?? '')) as $part) {
            if ($part === '') {
                continue;
            }
            [$rawName, $rawValue] = array_pad(explode('=', $part, 2), 2, '');
            $name = urldecode($rawName);
            if (in_array(strtolower($name), self::REVIEWLAYER_PARAMS, true)) {
                continue;
            }
            $pairs[] = [$name, urldecode($rawValue)];
        }
        usort($pairs, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $query = implode('&', array_map(
            static fn (array $pair): string => urlencode((string) $pair[0]) . '=' . urlencode((string) $pair[1]),
            $pairs
        ));
        $fragment = isset($parts['fragment']) && $parts['fragment'] !== '' ? '#' . $parts['fragment'] : '';
        return $scheme . '://' . $host . $portPart . $path . ($query !== '' ? '?' . $query : '') . $fragment;
    }

    /** @return list<string> */
    public static function compatiblePageKeys(string $pageKey): array
    {
        $canonical = self::canonicalPageKey($pageKey);
        $parts = parse_url($canonical);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return [$canonical];
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);
        $portPart = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $suffix = (string) ($parts['path'] ?? '/');
        $suffix .= isset($parts['query']) ? '?' . $parts['query'] : '';
        $suffix .= isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

        $schemes = [$scheme];
        if ($portPart === '') {
            $schemes[] = $scheme === 'https' ? 'http' : 'https';
        }

        $hosts = [$host];
        $plainHost = trim($host, '[]');
        $canUseWwwAlias = $plainHost !== 'localhost'
            && filter_var($plainHost, FILTER_VALIDATE_IP) === false
            && str_contains($plainHost, '.');
        if ($canUseWwwAlias) {
            $hosts[] = str_starts_with($host, 'www.') ? substr($host, 4) : 'www.' . $host;
        }

        $keys = [];
        foreach ($schemes as $candidateScheme) {
            foreach ($hosts as $candidateHost) {
                $candidate = $candidateScheme . '://' . $candidateHost . $portPart . $suffix;
                $keys[$candidate] = true;
            }
        }
        return array_keys($keys);
    }

    /** @param array<string, mixed> $config */
    public static function pageContext(mixed $pageKey, mixed $pageUrl, array $config = []): array
    {
        $absoluteUrl = self::pageUrl($pageUrl);
        $storedUrl = self::pageUrlForRequest($absoluteUrl, $config);
        $canonical = self::canonicalPageKey($absoluteUrl);
        $provided = self::string($pageKey, 'page_key', 8, 4096);
        if (!hash_equals($canonical, $provided)) {
            throw new InvalidArgumentException('page_key does not match page_url.');
        }
        return ['page_key' => $canonical, 'page_url' => $storedUrl];
    }

    public static function selector(mixed $value, string $field = 'selector'): string
    {
        $selector = self::string($value, $field, 1, 2048);
        if (preg_match('/[\x00-\x1F\x7F]/', $selector) === 1) {
            throw new InvalidArgumentException($field . ' contains control characters.');
        }
        $plain = preg_replace('/\\\\(?:[0-9a-fA-F]{1,6}[ \t\r\n\f]?|.)/u', '', $selector);
        $plain = is_string($plain) ? $plain : $selector;
        $withoutGeneratedPseudo = preg_replace('/:nth-of-type\([1-9][0-9]{0,5}\)/i', '', $plain);
        $withoutGeneratedPseudo = is_string($withoutGeneratedPseudo) ? $withoutGeneratedPseudo : $plain;
        if (preg_match('/[*,+~:]/', $withoutGeneratedPseudo) === 1 || substr_count($withoutGeneratedPseudo, '>') > 8) {
            throw new InvalidArgumentException($field . ' uses an unsupported selector form.');
        }
        return $selector;
    }

    /** @return array<string, mixed> */
    public static function object(mixed $value, string $field, int $maxEncodedLength = 32768): array
    {
        // PHP associative JSON decoding cannot distinguish {} from [] when
        // either value is empty. Empty objects are safe and valid here.
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidArgumentException($field . ' must be an object.');
        }
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($encoded) > $maxEncodedLength) {
            throw new InvalidArgumentException($field . ' is too large.');
        }
        return $value;
    }

    public static function status(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, ['open', 'resolved'], true)) {
            throw new InvalidArgumentException('status is invalid.');
        }
        return $value;
    }

    public static function roleKey(mixed $value): string
    {
        return self::oneOf($value, 'role_key', ['unassigned', 'editor', 'developer', 'designer', 'generalist']);
    }

    public static function audienceRole(mixed $value): string
    {
        return self::oneOf($value, 'audience_role', ['all', 'editor', 'developer', 'designer']);
    }

    public static function oneOf(mixed $value, string $field, array $allowed): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new InvalidArgumentException($field . ' is invalid.');
        }
        return $value;
    }

    private static function requestHost(): string
    {
        $rawHost = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if ($rawHost === '' || preg_match('/[\x00-\x20\x7F]/', $rawHost) === 1) {
            return '';
        }
        $parts = parse_url('http://' . $rawHost);
        if (!is_array($parts) || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return '';
        }
        if (isset($parts['path']) && $parts['path'] !== '') {
            return '';
        }
        return strtolower(rtrim((string) $parts['host'], '.'));
    }

    private static function normalizeHost(string $value): string
    {
        $value = trim($value);
        if ($value === '' || preg_match('/[\x00-\x20\x7F\/:@?#]/', $value) === 1) {
            return '';
        }
        $parts = parse_url('http://' . $value);
        if (!is_array($parts) || !isset($parts['host']) || isset($parts['port']) || isset($parts['path'])) {
            return '';
        }
        return strtolower(rtrim((string) $parts['host'], '.'));
    }

    private static function relativePageReference(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            throw new InvalidArgumentException('page_url is invalid.');
        }
        $path = isset($parts['path']) && $parts['path'] !== '' ? (string) $parts['path'] : '/';
        $query = isset($parts['query']) ? '?' . (string) $parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#' . (string) $parts['fragment'] : '';
        return $path . $query . $fragment;
    }
}

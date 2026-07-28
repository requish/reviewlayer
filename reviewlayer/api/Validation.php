<?php

declare(strict_types=1);

namespace ReviewLayer;

use InvalidArgumentException;

final class Validation
{
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
        return $value;
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
        return $url;
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

    public static function pageContext(mixed $pageKey, mixed $pageUrl): array
    {
        $url = self::pageUrl($pageUrl);
        $canonical = self::canonicalPageKey($url);
        $provided = self::string($pageKey, 'page_key', 8, 4096);
        if (!hash_equals($canonical, $provided)) {
            throw new InvalidArgumentException('page_key does not match page_url.');
        }
        return ['page_key' => $canonical, 'page_url' => $url];
    }

    /** @return array<string, mixed> */
    public static function object(mixed $value, string $field, int $maxEncodedLength = 32768): array
    {
        if (!is_array($value) || array_is_list($value)) {
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
}

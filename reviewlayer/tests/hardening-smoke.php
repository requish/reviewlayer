<?php

declare(strict_types=1);

use ReviewLayer\Security;
use ReviewLayer\SecurityException;
use ReviewLayer\Validation;

require_once dirname(__DIR__) . '/api/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function hardeningAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'reviewlayer-hardening-' . bin2hex(random_bytes(6));
if (!mkdir($temporary, 0700, true) && !is_dir($temporary)) {
    throw new RuntimeException('Unable to create the hardening test directory.');
}

$_SERVER['HTTP_HOST'] = 'prototype.example.com';
$_SERVER['REMOTE_ADDR'] = '192.0.2.10';
$_SERVER['HTTP_X_REVIEWLAYER_ACCESS'] = 'wrong-code';
ini_set('session.save_path', sys_get_temp_dir());
session_id('reviewlayerhardening' . bin2hex(random_bytes(6)));

try {
    $config = ReviewLayer\loadConfig();
    $config['DATA_DIRECTORY'] = $temporary;
    $config['PERSISTENT_RATE_LIMIT'] = true;
    $config['RATE_LIMIT_MAX_BUCKETS'] = 10;
    $config['RATE_LIMIT_REQUESTS'] = 1;
    $config['RATE_LIMIT_WINDOW_SECONDS'] = 60;
    $config['ACCESS_RATE_LIMIT_REQUESTS'] = 1;
    $config['ACCESS_RATE_LIMIT_WINDOW_SECONDS'] = 60;

    $portableContext = Validation::pageContext(
        'https://prototype.example.com/path',
        'https://prototype.example.com/path',
        [
            'REQUIRE_SAME_HOST_PAGE_URL' => true,
            'ALLOWED_PAGE_HOSTS' => [],
            'NOTIFICATION_PUBLIC_BASE_URL' => '',
        ]
    );
    hardeningAssert($portableContext['page_url'] === '/path', 'Portable mode retained an untrusted absolute page host.');

    $trustedHostConfig = [
        'REQUIRE_SAME_HOST_PAGE_URL' => true,
        'ALLOWED_PAGE_HOSTS' => ['prototype.example.com'],
        'NOTIFICATION_PUBLIC_BASE_URL' => '',
    ];
    Validation::assertTrustedRequestHost($trustedHostConfig);
    $context = Validation::pageContext(
        'https://prototype.example.com/path',
        'https://prototype.example.com/path',
        $trustedHostConfig
    );
    hardeningAssert($context['page_url'] === 'https://prototype.example.com/path', 'A trusted page URL was rejected.');

    $_SERVER['HTTP_HOST'] = 'attacker.example';
    $foreignRejected = false;
    try {
        Validation::assertTrustedRequestHost($trustedHostConfig);
    } catch (InvalidArgumentException) {
        $foreignRejected = true;
    }
    hardeningAssert($foreignRejected, 'An unrecognized request Host was accepted.');
    $_SERVER['HTTP_HOST'] = 'prototype.example.com';

    $foreignRejected = false;
    try {
        Validation::pageContext('https://attacker.example/path', 'https://attacker.example/path', $trustedHostConfig);
    } catch (InvalidArgumentException) {
        $foreignRejected = true;
    }
    hardeningAssert($foreignRejected, 'A foreign absolute page URL was accepted in trusted-host mode.');

    hardeningAssert(Validation::selector('#save') === '#save', 'An ordinary generated selector was rejected.');
    hardeningAssert(Validation::object([], 'empty_object') === [], 'An empty decoded JSON object was rejected.');
    foreach (['*', 'div,iframe', 'body:has(form)', 'main + aside'] as $unsafeSelector) {
        $selectorRejected = false;
        try {
            Validation::selector($unsafeSelector);
        } catch (InvalidArgumentException) {
            $selectorRejected = true;
        }
        hardeningAssert($selectorRejected, 'An unsupported selector was accepted: ' . $unsafeSelector);
    }

    Validation::configureAllowedProjectKeys(['default']);
    hardeningAssert(Validation::projectKey('default') === 'default', 'The allowed project key was rejected.');
    $projectRejected = false;
    try {
        Validation::projectKey('other');
    } catch (InvalidArgumentException) {
        $projectRejected = true;
    }
    hardeningAssert($projectRejected, 'A project outside ALLOWED_PROJECT_KEYS was accepted.');
    Validation::configureAllowedProjectKeys([]);

    $security = new Security($config);
    $security->rateLimit('fixed-action');
    $mutationLimited = false;
    try {
        $security->rateLimit('fixed-action');
    } catch (SecurityException $error) {
        $mutationLimited = $error->errorCode === 'RATE_LIMITED';
    }
    hardeningAssert($mutationLimited, 'The persistent fixed-action limit did not reject the second request.');

    $protectedConfig = $config;
    $protectedConfig['ALLOW_GUESTS'] = false;
    $protectedConfig['PROJECT_ACCESS_CODE'] = 'correct-code';
    $protectedSecurity = new Security($protectedConfig);
    $firstDenied = false;
    try {
        $protectedSecurity->assertProjectAccess();
    } catch (SecurityException $error) {
        $firstDenied = $error->errorCode === 'ACCESS_DENIED';
    }
    hardeningAssert($firstDenied, 'The first invalid project code did not fail normally.');

    $accessLimited = false;
    try {
        $protectedSecurity->assertProjectAccess();
    } catch (SecurityException $error) {
        $accessLimited = $error->errorCode === 'RATE_LIMITED';
    }
    hardeningAssert($accessLimited, 'Repeated invalid project codes were not rate-limited.');

    echo "ReviewLayer security hardening smoke test passed.\n";
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    foreach (glob($temporary . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
        if (is_file($path)) unlink($path);
    }
    foreach (glob($temporary . DIRECTORY_SEPARATOR . '.*') ?: [] as $path) {
        if (is_file($path)) unlink($path);
    }
    rmdir($temporary);
}

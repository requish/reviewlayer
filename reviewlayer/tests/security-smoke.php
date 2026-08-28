<?php

declare(strict_types=1);

use ReviewLayer\Security;
use ReviewLayer\SecurityException;

require_once dirname(__DIR__) . '/api/Security.php';

ini_set('session.save_path', sys_get_temp_dir());
session_id('reviewlayersecurity' . bin2hex(random_bytes(6)));

$config = [
    'PROJECT_ACCESS_CODE' => 'project-code',
    'ADMIN_ACCESS_CODE' => 'admin-code',
    'PROJECT_ACCESS_CODE_HASH' => '',
    'ADMIN_ACCESS_CODE_HASH' => '',
    'ALLOW_GUESTS' => false,
    'ALLOW_AUTHOR_DELETE_OWN_MESSAGES' => true,
    'ALLOW_AUTHOR_DELETE_OWN_PINS' => true,
    'RATE_LIMIT_WINDOW_SECONDS' => 60,
    'RATE_LIMIT_REQUESTS' => 30,
];

$_SERVER['HTTP_X_REVIEWLAYER_ACCESS'] = 'project-code';
$security = new Security($config);
$security->assertProjectAccess();
$security->assertAdmin('admin-code');

$foreignPin = ['author_id' => 'author-one'];
if ($security->canDeletePin($foreignPin, 'author-two', '')) {
    throw new RuntimeException('A foreign pin was deletable without the configured administrator code.');
}
if (!$security->canDeletePin($foreignPin, 'author-two', 'admin-code')) {
    throw new RuntimeException('The configured administrator code could not delete a foreign pin.');
}

$configWithoutAdmin = $config;
$configWithoutAdmin['ADMIN_ACCESS_CODE'] = '';
$securityWithoutAdmin = new Security($configWithoutAdmin);
if ($securityWithoutAdmin->adminCodeConfigured()) {
    throw new RuntimeException('An empty administrator code was reported as configured.');
}
$unconfiguredRejected = false;
try {
    $securityWithoutAdmin->assertAdmin('');
} catch (SecurityException) {
    $unconfiguredRejected = true;
}
if (!$unconfiguredRejected || $securityWithoutAdmin->canDeletePin($foreignPin, 'author-two', '')) {
    throw new RuntimeException('Unconfigured administrator actions were not disabled.');
}
if (!$securityWithoutAdmin->canDeletePin($foreignPin, 'author-one', '')) {
    throw new RuntimeException('The author could not delete their own pin while administrator actions were disabled.');
}

$rejected = false;
try {
    $security->assertAdmin('wrong-code');
} catch (SecurityException) {
    $rejected = true;
}

if (!$rejected) {
    throw new RuntimeException('An invalid plaintext administrator code was accepted.');
}

session_destroy();
fwrite(STDOUT, "Plaintext project and administrator code smoke test passed.\n");

<?php

declare(strict_types=1);

use ReviewLayer\Validation;

require_once dirname(__DIR__) . '/api/bootstrap.php';

$aliases = Validation::compatiblePageKeys('http://www.pro.doniczki.ladecora.pl/');
$expected = [
    'http://www.pro.doniczki.ladecora.pl/',
    'http://pro.doniczki.ladecora.pl/',
    'https://www.pro.doniczki.ladecora.pl/',
    'https://pro.doniczki.ladecora.pl/',
];
if ($aliases !== $expected) {
    throw new RuntimeException('HTTP/HTTPS and www aliases are invalid: ' . json_encode($aliases));
}

$portAliases = Validation::compatiblePageKeys('https://example.com:8443/path?a=1#/route');
if ($portAliases !== [
    'https://example.com:8443/path?a=1#/route',
    'https://www.example.com:8443/path?a=1#/route',
]) {
    throw new RuntimeException('A non-default port was incorrectly merged across schemes.');
}

$localAliases = Validation::compatiblePageKeys('http://localhost/demo');
if ($localAliases !== ['http://localhost/demo', 'https://localhost/demo']) {
    throw new RuntimeException('Localhost unexpectedly received a www alias.');
}

fwrite(STDOUT, "Page alias compatibility smoke test passed.\n");

<?php

declare(strict_types=1);

$requestedLanguage = isset($_GET['lang']) && is_string($_GET['lang']) ? strtolower($_GET['lang']) : '';
if (!in_array($requestedLanguage, ['pl', 'en'], true)) {
    $requestedLanguage = 'en';
}
$translationPath = __DIR__ . '/assets/i18n/' . $requestedLanguage . '.json';
$translations = json_decode((string) file_get_contents($translationPath), true, 32, JSON_THROW_ON_ERROR);
$translate = static fn (string $key): string => htmlspecialchars((string) ($translations[$key] ?? $key), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$yesNo = static fn (bool $value): string => $translate($value ? 'yes' : 'no');

$configPresent = is_file(__DIR__ . '/config.php');
$dataWritable = is_dir(__DIR__ . '/data') && is_writable(__DIR__ . '/data');
$sqliteAvailable = extension_loaded('pdo_sqlite');
$storageMode = $sqliteAvailable ? 'SQLite' : 'JSON';
$configurationStatus = '';

try {
    require_once __DIR__ . '/api/bootstrap.php';
    $config = ReviewLayer\loadConfig();
    $storage = ReviewLayer\createStorage($config);
    $storageMode = strtoupper($storage->mode());
} catch (Throwable $error) {
    $configurationStatus = $translate('configurationError');
}

$scriptPath = (string) ($_SERVER['SCRIPT_NAME'] ?? '/reviewlayer/index.php');
$basePath = rtrim(str_replace('\\', '/', dirname($scriptPath)), '/');
$apiPath = ($basePath === '' ? '' : $basePath) . '/api/index.php?action=health';
$readme = $requestedLanguage === 'pl' ? 'README_PL.md' : 'README_EN.md';
?>
<!doctype html>
<html lang="<?= $requestedLanguage ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $translate('diagnosticsTitle') ?></title>
  <style>
    * { box-sizing: border-box; }
    body { margin: 0; color: #18202d; font-family: Inter, ui-sans-serif, system-ui, sans-serif; background: #f3f5f8; }
    main { width: min(100% - 32px, 880px); margin: 48px auto; }
    header { display: flex; align-items: flex-start; justify-content: space-between; gap: 24px; margin-bottom: 24px; }
    h1 { margin: 0 0 8px; font-size: clamp(2rem, 5vw, 3.5rem); line-height: 1; }
    p { margin: 0; color: #647084; line-height: 1.6; }
    nav { display: flex; gap: 7px; }
    nav a, .actions a { display: inline-flex; padding: 9px 12px; color: #4b37c5; font-weight: 600; text-decoration: none; background: #fff; border: 1px solid #dce2ea; border-radius: 9px; }
    nav a[aria-current="page"] { color: #fff; background: #5b45e0; border-color: #5b45e0; }
    section { overflow: hidden; background: #fff; border: 1px solid #dce2ea; border-radius: 16px; box-shadow: 0 14px 40px rgba(24, 32, 45, .08); }
    dl { margin: 0; }
    dl div { display: grid; grid-template-columns: minmax(180px, 1fr) 1fr; gap: 20px; padding: 15px 20px; border-bottom: 1px solid #e7ebf0; }
    dl div:last-child { border: 0; }
    dt { color: #647084; }
    dd { margin: 0; font-weight: 600; overflow-wrap: anywhere; }
    .error { margin: 18px 0 0; padding: 13px 15px; color: #8f1d15; background: #fff0ee; border: 1px solid #f3c1bb; border-radius: 10px; }
    .actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 20px; }
    .actions a:first-child { color: #fff; background: #5b45e0; border-color: #5b45e0; }
    @media (max-width: 620px) { main { margin: 24px auto; } header { display: grid; } dl div { grid-template-columns: 1fr; gap: 5px; } }
  </style>
</head>
<body>
  <main>
    <header>
      <div>
        <h1><?= $translate('diagnosticsTitle') ?></h1>
        <p><?= $translate('diagnosticsIntro') ?></p>
      </div>
      <nav aria-label="<?= $translate('language') ?>">
        <a href="?lang=pl"<?= $requestedLanguage === 'pl' ? ' aria-current="page"' : '' ?>>PL</a>
        <a href="?lang=en"<?= $requestedLanguage === 'en' ? ' aria-current="page"' : '' ?>>EN</a>
      </nav>
    </header>
    <section>
      <dl>
        <div><dt><?= $translate('phpVersion') ?></dt><dd><?= htmlspecialchars(PHP_VERSION, ENT_QUOTES, 'UTF-8') ?></dd></div>
        <div><dt><?= $translate('pdoAvailable') ?></dt><dd><?= $yesNo(extension_loaded('pdo')) ?></dd></div>
        <div><dt><?= $translate('sqliteAvailable') ?></dt><dd><?= $yesNo($sqliteAvailable) ?></dd></div>
        <div><dt><?= $translate('dataWritable') ?></dt><dd><?= $yesNo($dataWritable) ?></dd></div>
        <div><dt><?= $translate('storageMode') ?></dt><dd><?= htmlspecialchars($storageMode, ENT_QUOTES, 'UTF-8') ?></dd></div>
        <div><dt><?= $translate('configPresent') ?></dt><dd><?= $yesNo($configPresent) ?></dd></div>
        <div><dt><?= $translate('dataProtection') ?></dt><dd><?= is_file(__DIR__ . '/data/.htaccess') ? $translate('apacheRulesPresent') : $translate('serverProtectionNote') ?></dd></div>
        <div><dt><?= $translate('apiAddress') ?></dt><dd><?= htmlspecialchars($apiPath, ENT_QUOTES, 'UTF-8') ?></dd></div>
        <div><dt><?= $translate('version') ?></dt><dd><?= htmlspecialchars(ReviewLayer\REVIEWLAYER_VERSION, ENT_QUOTES, 'UTF-8') ?></dd></div>
      </dl>
    </section>
    <?php if ($configurationStatus !== ''): ?>
      <p class="error"><?= $configurationStatus ?></p>
    <?php endif; ?>
    <div class="actions">
      <a href="<?= htmlspecialchars($apiPath, ENT_QUOTES, 'UTF-8') ?>"><?= $translate('healthCheck') ?></a>
      <a href="<?= htmlspecialchars($readme, ENT_QUOTES, 'UTF-8') ?>"><?= $translate('documentation') ?></a>
    </div>
  </main>
</body>
</html>

import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import { createHash } from 'node:crypto';

const testDirectory = dirname(fileURLToPath(import.meta.url));
const appDirectory = resolve(testDirectory, '..');
const projectDirectory = resolve(appDirectory, '..');

async function read(relativePath) {
  return readFile(resolve(appDirectory, relativePath), 'utf8');
}

test('Polish and English translations expose identical keys', async () => {
  const [pl, en] = await Promise.all([
    read('assets/i18n/pl.json').then(JSON.parse),
    read('assets/i18n/en.json').then(JSON.parse)
  ]);
  assert.deepEqual(Object.keys(pl).sort(), Object.keys(en).sort());
  assert.ok(Object.keys(pl).length > 100);
});

test('test page integrates ReviewLayer through one script tag only', async () => {
  const html = await readFile(resolve(projectDirectory, 'index.html'), 'utf8');
  const head = html.match(/<head>([\s\S]*?)<\/head>/)?.[1] || '';
  const matches = html.match(/<script\b[^>]*reviewlayer\/embed\.js[^>]*><\/script>/g) || [];
  assert.equal(matches.length, 1);
  assert.match(matches[0], /data-project="default"/);
  assert.match(matches[0], /data-lang="en"/);
  assert.match(head, /<script src="\/reviewlayer\/embed\.js" data-project="default" data-lang="en" defer><\/script>\s*<meta name="robots" content="noindex,nofollow">/);
});

test('embed discovers its own base URL and has no configured API URL', async () => {
  const embed = await read('embed.js');
  assert.match(embed, /language: script\.dataset\.lang \|\| 'en'/);
  assert.match(embed, /document\.currentScript/);
  assert.match(embed, /new URL\('\.\/\', script\.src\)/);
  assert.doesNotMatch(embed, /https?:\/\//);
});

test('pin visibility disables interaction and is project-scoped', async () => {
  const [app, css] = await Promise.all([read('assets/app.js'), read('assets/reviewlayer.css')]);
  assert.match(app, /reviewlayer:\$\{this\.projectKey\}:pins-visible/);
  assert.match(app, /event\.altKey.*key\.toLowerCase\(\) === 'p'/s);
  assert.match(css, /\.rl-pins-layer\.is-hidden[\s\S]*visibility:\s*hidden[\s\S]*pointer-events:\s*none/);
});

test('toolbar uses available width and persists its top or bottom position', async () => {
  const [app, css] = await Promise.all([read('assets/app.js'), read('assets/reviewlayer.css')]);
  assert.match(app, /reviewlayer:\$\{this\.projectKey\}:toolbar-position/);
  assert.match(app, /data-action="toggle-toolbar-position"/);
  assert.match(app, /role="switch"/);
  assert.match(css, /\.rl-toolbar[\s\S]*width:\s*max-content/);
  assert.match(css, /\.rl-button[\s\S]*white-space:\s*nowrap/);
  assert.match(css, /\.rl-toolbar\.is-top[\s\S]*env\(safe-area-inset-top\)/);
  assert.match(css, /\.rl-toolbar\.is-bottom[\s\S]*env\(safe-area-inset-bottom\)/);
  assert.doesNotMatch(css, /\.rl-position-switch\[aria-checked="true"\][^{]*\{[^}]*background:/);
});

test('settings contain a protected 2B.Design attribution footer', async () => {
  const [app, css] = await Promise.all([read('assets/app.js'), read('assets/reviewlayer.css')]);
  const attribution = '2B.Design Web Studio|https://www.web.2b.design/';
  const checksum = createHash('sha256').update(attribution).digest('hex');
  assert.match(app, new RegExp(checksum));
  assert.match(app, /data-reviewlayer-attribution/);
  assert.match(app, /new MutationObserver\(ensureAttribution\)/);
  assert.match(app, /rel="noopener noreferrer external"/);
  assert.match(css, /\.rl-settings-body[\s\S]*flex-direction:\s*column/);
  assert.match(css, /\.rl-attribution[\s\S]*margin-top:\s*auto/);
});

test('single-pin deletion uses DEL while bulk deletion keeps DELETE', async () => {
  const [app, api] = await Promise.all([read('assets/app.js'), read('api/index.php')]);
  assert.match(app, /confirmation\.trim\(\)\.toUpperCase\(\) !== 'DEL'/);
  assert.match(api, /\$expectedConfirmation = \$scope === 'all_projects' \? 'DELETE ALL REVIEWLAYER DATA' : 'DELETE'/);
});

test('admin code is optional when it is not configured', async () => {
  const [app, api, security] = await Promise.all([
    read('assets/app.js'),
    read('api/index.php'),
    read('api/Security.php')
  ]);
  assert.match(api, /'admin_code_configured' => \$security->adminCodeConfigured\(\)/);
  assert.match(api, /\$security->adminCodeConfigured\(\)[\s\S]*\? Validation::string[\s\S]*: ''/);
  assert.match(app, /this\.bootstrapData\?\.admin_code_configured === false[\s\S]*\? ''/);
  assert.match(security, /adminActionsEnabled\(\)[\s\S]*ALLOW_ADMIN_WITHOUT_CODE/);
});

test('settings expose a separate manual backup action', async () => {
  const [app, client, api, css] = await Promise.all([
    read('assets/app.js'),
    read('assets/api-client.js'),
    read('api/index.php'),
    read('assets/reviewlayer.css')
  ]);
  assert.match(app, /class="rl-settings-section"[\s\S]*data-action="create-backup"/);
  assert.match(app, /this\.api\.createBackup/);
  assert.match(client, /createBackup\(body, signal\)/);
  assert.match(api, /\$action === 'create-backup'/);
  assert.match(api, /new BackupService\(dirname\(__DIR__\) \. '\/data\/backups', \(int\) \$config\['MAX_BACKUPS'\]\)/);
  assert.match(css, /\.rl-settings-section/);
  assert.match(app, /admin_actions_enabled === false/);
});

test('API hides author ownership identifiers and enforces configurable demo limits', async () => {
  const [api, limits, bootstrap] = await Promise.all([
    read('api/index.php'),
    read('api/UsageLimits.php'),
    read('api/bootstrap.php')
  ]);
  assert.match(api, /unset\(\$message\['author_id'\]\)/);
  assert.match(api, /unset\(\$pin\['author_id'\]\)/);
  assert.match(api, /assertCanCreatePin/);
  assert.match(api, /assertCanAddMessage/);
  assert.match(limits, /MAX_PINS_PER_AUTHOR/);
  assert.match(limits, /MAX_MESSAGES_PER_PIN/);
  assert.match(bootstrap, /PERSISTENT_RATE_LIMIT/);
});

test('project pin overview is available from a hamburger button before settings', async () => {
  const [app, client, api, storage, database, jsonStorage, css] = await Promise.all([
    read('assets/app.js'),
    read('assets/api-client.js'),
    read('api/index.php'),
    read('api/StorageInterface.php'),
    read('api/Database.php'),
    read('api/JsonStorage.php'),
    read('assets/reviewlayer.css')
  ]);
  assert.match(app, /data-action="project-pins"[\s\S]*data-action="settings"/);
  assert.match(app, /data-action="open-project-pin"/);
  assert.match(client, /listProjectPins\(projectKey, signal\)/);
  assert.match(api, /\$action === 'list-project-pins'/);
  assert.match(storage, /listProjectPins\(string \$projectKey\)/);
  assert.match(database, /public function listProjectPins/);
  assert.match(jsonStorage, /public function listProjectPins/);
  assert.match(css, /\.rl-project-pins/);
});

test('runtime contains no external CDN dependency', async () => {
  const files = ['embed.js', 'assets/app.js', 'assets/api-client.js', 'assets/anchor.js', 'assets/reviewlayer.css'];
  for (const file of files) {
    const source = await read(file);
    assert.doesNotMatch(source, /(?:unpkg|jsdelivr|cdnjs|fonts\.googleapis|cdn\.)/i, file);
  }
});

test('data and backup directories contain direct-download protection', async () => {
  const [dataRules, backupRules] = await Promise.all([read('data/.htaccess'), read('data/backups/.htaccess')]);
  assert.match(dataRules, /Require all denied/);
  assert.match(backupRules, /Require all denied/);
});

test('both manuals cover visibility, clearing, backup, restore, storage, and uninstall', async () => {
  for (const file of ['README_PL.md', 'README_EN.md']) {
    const source = (await read(file)).toLowerCase();
    for (const term of ['alt + p', 'reviewlayer=clear', 'sqlite', 'json', 'backup', 'restore.php']) {
      assert.ok(source.includes(term), `${file} is missing ${term}`);
    }
  }
  assert.ok((await read('README_PL.md')).toLowerCase().includes('odinstalowanie'));
  assert.ok((await read('README_EN.md')).toLowerCase().includes('uninstall'));
});

test('both manuals place one-line embed calls and noindex in the head', async () => {
  for (const file of ['README_PL.md', 'README_EN.md']) {
    const source = await read(file);
    assert.match(source, /<head>/);
    assert.match(source, /<meta name="robots" content="noindex,nofollow">/);
    assert.match(source, /<script src="\/reviewlayer\/embed\.js" data-project="default" data-lang="en" defer><\/script>\n<meta name="robots" content="noindex,nofollow">/);
    assert.match(source, /<script src="\/reviewlayer\/embed\.js" data-project="shop-redesign" data-lang="pl" defer><\/script>\n<meta name="robots" content="noindex,nofollow">/);
    assert.doesNotMatch(source, /<script\s*\n\s*src="\/reviewlayer\/embed\.js"/);
    assert.doesNotMatch(source, /inline-loader\.html/);
  }
});

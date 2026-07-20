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

test('pin tooltip stays close to its marker', async () => {
  const css = await read('assets/reviewlayer.css');
  assert.match(css, /\.rl-pin > \.rl-pin-tooltip\s*\{[\s\S]*?left:\s*35px;[\s\S]*?top:\s*auto;[\s\S]*?bottom:\s*35px;/);
  assert.match(css, /\.rl-pin > \.rl-pin-tooltip\s*\{[\s\S]*?transform-origin:\s*left bottom;/);
});

test('hidden hover targets use a parent pin and an exact-position mention marker', async () => {
  const [app, anchor, css, api, html] = await Promise.all([
    read('assets/app.js'),
    read('assets/anchor.js'),
    read('assets/reviewlayer.css'),
    read('api/index.php'),
    readFile(resolve(projectDirectory, 'index.html'), 'utf8')
  ]);
  assert.match(anchor, /export function positionAnchor/);
  assert.match(anchor, /fallback_ancestors: fallbackAncestors/);
  assert.match(anchor, /interaction_trigger: interactionTrigger/);
  assert.match(anchor, /findInteractionTrigger/);
  assert.match(anchor, /rememberInteractionTrigger\(pin, bestElement\)/);
  assert.match(anchor, /relative_x: \(clientX - ancestorRect\.left\) \/ ancestorRect\.width/);
  assert.match(anchor, /function positionHiddenAnchor/);
  assert.match(anchor, /resolveStoredElement\(anchor\.interaction_trigger\)/);
  assert.match(anchor, /if \(triggerRect\)[\s\S]*if \(targetPoint\)/);
  assert.match(anchor, /bestScore >= 6/);
  assert.match(anchor, /mention: mentionDistance >= 12/);
  assert.match(app, /class="rl-pin-mention/);
  assert.match(app, /data-pin-mention-id/);
  assert.match(anchor, /expandedState === null \? Boolean\(targetPoint\) : expandedState === 'true'/);
  assert.match(anchor, /mentionAutoVisible: Boolean\(position\.mention && targetPoint && interactionActive\)/);
  assert.match(app, /position\.mention && \(position\.mentionAutoVisible \|\| this\.currentPin\?\.id === pin\.id\)/);
  assert.match(anchor, /resolveInteractionTrigger\(pin\)/);
  assert.match(app, /data-reviewlayer-interaction-preview/);
  assert.match(app, /trigger\.setAttribute\('aria-expanded', 'true'\)/);
  assert.match(app, /function dispatchInteractionHoverEvents\(trigger, active\)/);
  assert.match(app, /\['pointerover', 'pointer', true\]/);
  assert.match(app, /\['mouseover', 'mouse', true\]/);
  assert.match(app, /\['pointerout', 'pointer', true\]/);
  assert.match(app, /this\.reinforceInteractionPreview\(true\)/);
  assert.match(app, /this\.syncInteractionPreview\(\)/);
  assert.match(app, /document\.addEventListener\('pointermove', \(event\) => this\.handlePagePointerMove\(event\)/);
  assert.match(app, /this\.schedulePositions\(\)/);
  assert.doesNotMatch(app, /focusedInteractionPinId/);
  assert.doesNotMatch(html, /class="demo-hover-trigger"[^>]*aria-expanded="false"/);
  assert.match(app, /--rl-mention-angle/);
  assert.match(app, /this\.tempAnchor\.interaction_state = 'hover'/);
  assert.match(anchor, /const interactionState = detectInteractionState\(element\)/);
  assert.match(anchor, /interaction_state: interactionState/);
  assert.match(anchor, /selectorHasHoverDependentTarget/);
  assert.match(app, /interactionState === 'hover' \? ' \(:hover\)' : ''/);
  assert.match(app, /class="rl-pin-tooltip-hint"/);
  assert.match(app, /pinTooltipHoverHint/);
  assert.match(css, /\.rl-pin-mention/);
  assert.match(css, /\.rl-pin\.is-hover[\s\S]*calc\(-75% - 16px\)/);
  assert.match(css, /\.rl-pin-tooltip-hint[\s\S]*margin-top:\s*3px;[\s\S]*padding-top:\s*3px;[\s\S]*border-top:/);
  assert.match(app, /positionAnchor\(this\.tempTarget, this\.tempAnchor\)/);
  assert.match(api, /\$output\['fallback_ancestors'\]/);
  assert.match(api, /\$output\['interaction_state'\] = 'hover'/);
  assert.match(api, /\$output\['interaction_trigger'\]/);
});

test('add mode preserves native page hover and captures only the target click', async () => {
  const app = await read('assets/app.js');
  assert.match(app, /document\.addEventListener\('pointermove', this\.captureMove, true\)/);
  assert.match(app, /document\.addEventListener\('click', this\.captureClick, true\)/);
  assert.match(app, /event\.composedPath\(\)\.includes\(this\.host\)/);
  assert.match(app, /event\.stopImmediatePropagation\(\)/);
  assert.match(app, /this\.captureLayer\.hidden = true/);
  assert.match(app, /this\.instruction\.hidden = true/);
  assert.doesNotMatch(app, /this\.captureLayer\.addEventListener\('pointermove'/);
});

test('Ctrl or Command plus Enter submits comment forms only', async () => {
  const app = await read('assets/app.js');
  assert.match(app, /\(event\.ctrlKey \|\| event\.metaKey\)/);
  assert.match(app, /event\.target instanceof HTMLTextAreaElement/);
  assert.match(app, /form\[data-form="create-pin"\], form\[data-form="reply"\]/);
  assert.match(app, /form\.requestSubmit\(\)/);
});

test('status actions use a green resolve CTA and a blue reopen CTA with dedicated icons', async () => {
  const [app, css] = await Promise.all([read('assets/app.js'), read('assets/reviewlayer.css')]);
  assert.match(app, /rl-button rl-status-action/);
  assert.doesNotMatch(app, /rl-button rl-button-primary rl-status-action/);
  assert.match(app, /materialIcon\('select_check_box'\)/);
  assert.match(app, /pin\.status === 'resolved'[\s\S]*rl-status-action is-reopen[\s\S]*materialIcon\('reopen_window'\)/);
  assert.match(css, /\.rl-status-action[\s\S]*min-height:\s*34px;[\s\S]*color:\s*#fff;[\s\S]*background:\s*#08775a;/);
  assert.match(css, /\.rl-status-action:hover[\s\S]*background:\s*#06634b;/);
  assert.match(css, /\.rl-status-action\.is-reopen[\s\S]*background:\s*#1976d2;/);
  assert.match(css, /\.rl-status-action\.is-reopen:hover[\s\S]*background:\s*#1565c0;/);
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

test('viewport filters use device icons and move to a second toolbar row on mobile', async () => {
  const [app, css] = await Promise.all([read('assets/app.js'), read('assets/reviewlayer.css')]);
  assert.match(app, /filterIcon\(filter\)/);
  assert.match(app, /filter === 'all' \? FILTERS\.slice\(1\)/);
  assert.match(app, /data-filter="\$\{filter\}"[\s\S]*aria-label=[\s\S]*title=[\s\S]*this\.filterIcon\(filter\)/);
  assert.match(css, /\.rl-filter-icons\.is-all/);
  assert.match(css, /@media \(max-width: 520px\)[\s\S]*\.rl-toolbar[\s\S]*flex-wrap:\s*wrap;[\s\S]*\.rl-filters[\s\S]*flex:\s*0 0 100%;[\s\S]*order:\s*2;/);
  assert.doesNotMatch(css, /@media \(max-width: 780px\)[\s\S]*?\.rl-filters\s*\{[^}]*display:\s*none;/);
  assert.match(css, /@media \(max-width: 410px\)[\s\S]*\.rl-visibility > \.rl-label-short\s*\{[^}]*display:\s*none;/);
  assert.doesNotMatch(css, /\.rl-visibility > \.rl-material-icon\s*\{[^}]*display:\s*none;/);
});

test('mobile demo facts keep labels and values separated', async () => {
  const css = await readFile(resolve(projectDirectory, 'assets/reviewlayer-demo.css'), 'utf8');
  assert.match(css, /@media \(max-width: 430px\)[\s\S]*\.hero-facts div\s*\{[^}]*grid-template-columns:\s*96px minmax\(0, 1fr\);[^}]*column-gap:\s*16px;/);
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
  assert.match(database, /p\.viewport_json/);
  assert.match(database, /'viewport'\] = \['device_type'/);
  assert.match(jsonStorage, /'viewport' => \['device_type'/);
  assert.match(app, /createNavigationPageKey\(targetUrl\) === createNavigationPageKey\(window\.location\)/);
  assert.match(app, /await this\.openConversation\(requestedPinId\)/);
  assert.match(css, /\.rl-project-pins/);
});

test('commenters receive persistent cycling colors visible across pins, messages, lists, and settings', async () => {
  const [app, client, api, storage, database, jsonStorage, css] = await Promise.all([
    read('assets/app.js'),
    read('assets/api-client.js'),
    read('api/index.php'),
    read('api/StorageInterface.php'),
    read('api/Database.php'),
    read('api/JsonStorage.php'),
    read('assets/reviewlayer.css')
  ]);
  assert.match(app, /const AUTHOR_COLORS = Object\.freeze\(\[/);
  assert.equal((app.match(/Object\.freeze\(\{ open: '#[0-9a-f]{6}', resolved: '#[0-9a-f]{6}' \}\)/g) || []).length, 10);
  assert.match(app, /open: '#4e5cc3', resolved: '#5a6597'/);
  assert.match(app, /authorColorStyle\(pin\.author_color_index\)/);
  assert.match(app, /authorBadge\(message\.author_name, message\.author_color_index\)/);
  assert.match(app, /authorBadge\(pin\.author_name, pin\.author_color_index\)/);
  assert.match(app, /data-role="project-users"/);
  assert.match(client, /listProjectUsers\(projectKey, signal\)/);
  assert.match(api, /\$action === 'list-project-users'/);
  assert.match(storage, /listProjectUsers\(string \$projectKey\)/);
  assert.match(database, /CREATE TABLE IF NOT EXISTS project_users/);
  assert.match(database, /\(\(\$sequenceNumber - 1\) % 10\) \+ 1/);
  assert.match(jsonStorage, /\(\(\$sequenceNumber - 1\) % 10\) \+ 1/);
  assert.match(css, /\.rl-pin\.is-resolved[\s\S]*var\(--rl-author-resolved-color/);
  assert.match(css, /\.rl-author-badge[\s\S]*padding:\s*1px 6px;[\s\S]*color:\s*#fff;[\s\S]*border-radius:\s*2px;/);
});

test('device badges identify pin viewport on markers, conversations, and project list', async () => {
  const [app, css] = await Promise.all([read('assets/app.js'), read('assets/reviewlayer.css')]);
  assert.match(app, /const DEVICE_ICONS/);
  assert.match(app, /this\.deviceBadge\(deviceType, 'pin'\)/);
  assert.match(app, /this\.deviceBadge\(viewport\.device_type, 'title'\)/);
  assert.match(app, /this\.deviceBadge\(pin\.viewport\?\.device_type, 'list'\)/);
  assert.match(css, /\.rl-device-badge\.is-pin[\s\S]*left:\s*17px;[\s\S]*top:\s*-7px;[\s\S]*width:\s*18px;[\s\S]*height:\s*18px;/);
  assert.match(css, /\.rl-device-badge\.is-pin > \.rl-material-icon[\s\S]*font-size:\s*14px;/);
  assert.match(css, /\.rl-device-badge\.is-title[\s\S]*background:\s*transparent;[\s\S]*border:\s*0;[\s\S]*box-shadow:\s*none;/);
  assert.match(css, /\.rl-device-badge\.is-title > \.rl-material-icon[\s\S]*font-size:\s*23px;/);
  assert.match(css, /\.rl-panel-header h2[\s\S]*font-size:\s*20px;/);
  assert.match(css, /\.rl-device-badge\.is-list[\s\S]*background:\s*transparent;[\s\S]*border:\s*0;[\s\S]*box-shadow:\s*none;/);
  assert.match(css, /\.rl-device-badge\.is-list > \.rl-material-icon[\s\S]*font-size:\s*19px;/);
  assert.match(css, /\.rl-project-pin-head strong[\s\S]*font-size:\s*14px;/);
});

test('conversation header locates, reveals, and highlights the current pin without closing the panel', async () => {
  const [app, css] = await Promise.all([read('assets/app.js'), read('assets/reviewlayer.css')]);
  const locateMethod = app.match(/locateCurrentPin\(\) \{([\s\S]*?)\n  \}\n\n  async submitReply/);
  assert.match(app, /data-action="locate-pin"/);
  assert.match(app, /locateCurrentPin\(\)/);
  assert.ok(locateMethod);
  assert.match(app, /this\.anchorResolver\.resolve\(pin\)/);
  assert.match(app, /this\.filter = 'all'/);
  assert.match(app, /this\.setPinsVisible\(true, false\)/);
  assert.doesNotMatch(locateMethod[1], /closePanel/);
  assert.match(app, /window\.scrollTo\(\{/);
  assert.match(app, /marker\.classList\.add\('is-located'\)/);
  assert.match(app, /panelFrame\(title, content, bodyClass = '', titlePrefix = '', titleAction = ''\)/);
  assert.match(css, /\.rl-locate-pin/);
  assert.match(css, /\.rl-pin\.is-located::before/);
  assert.match(css, /@keyframes rl-pin-located/);
});

test('runtime contains no external CDN dependency', async () => {
  const files = ['embed.js', 'assets/app.js', 'assets/api-client.js', 'assets/anchor.js', 'assets/reviewlayer.css'];
  for (const file of files) {
    const source = await read(file);
    assert.doesNotMatch(source, /(?:unpkg|jsdelivr|cdnjs|fonts\.googleapis|cdn\.)/i, file);
  }
});

test('icons use a self-hosted Material Symbols font without SVG markup', async () => {
  const [app, css, font, license] = await Promise.all([
    read('assets/app.js'),
    read('assets/reviewlayer.css'),
    readFile(resolve(appDirectory, 'assets/fonts/material-symbols-outlined.woff2')),
    read('assets/fonts/MATERIAL-SYMBOLS-LICENSE.txt')
  ]);

  assert.doesNotMatch(app, /<\/?svg\b/i);
  assert.match(app, /class="rl-material-icon"/);
  assert.match(app, /new FontFace\(/);
  assert.match(app, /document\.fonts\.add\(font\)/);
  assert.match(app, /await loadMaterialIconFont\(options\.baseUrl\)/);
  for (const icon of [
    'add',
    'arrow_downward',
    'arrow_forward',
    'arrow_upward',
    'close',
    'laptop',
    'menu',
    'refresh',
    'reopen_window',
    'select_check_box',
    'settings',
    'smartphone',
    'tablet',
    'visibility',
    'visibility_off'
  ]) {
    assert.ok(app.includes(`'${icon}'`), `missing Material Symbol: ${icon}`);
  }
  assert.match(css, /@font-face/);
  assert.match(css, /font-family:\s*"ReviewLayer Material Symbols"/);
  assert.match(css, /fonts\/material-symbols-outlined\.woff2/);
  assert.ok(font.length > 1000);
  assert.match(license, /Apache License\s+Version 2\.0/);
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

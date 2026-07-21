# ReviewLayer

ReviewLayer 1.2.4 is an independent annotation overlay for website prototypes. Clients can attach a note to a page element, discuss it, resolve it, and revisit it on another device. Installation needs no Node.js, build process, MySQL, CDN, or SaaS service.

## Server requirements

- PHP 8.0 or newer.
- `reviewlayer/data/` and `reviewlayer/data/backups/` writable by PHP.
- PDO SQLite is recommended. If `pdo_sqlite` is missing, the app automatically selects JSON with `flock` and atomic file replacement.
- The page and ReviewLayer directory should share one origin. Cross-origin installation needs custom CORS and cookie configuration, intentionally disabled by default.
- Apache reading `.htaccess`, or equivalent protection for `data` in Nginx/IIS.

Do not use `777`. Directories `750`/`770` and files `640`/`660` are commonly sufficient, depending on the hosting PHP user. Choose the least permissions that let PHP write data.

## FTP installation

1. Upload the complete `reviewlayer/` directory into the prototype's public directory.
2. Copy `config.example.php` to `config.php` and enter your chosen access codes.
3. Make `data/` and `data/backups/` writable by PHP.
4. Open `/reviewlayer/`. Diagnostics reports PHP, PDO, SQLite, write permission, selected storage, configuration, and health API address without exposing secrets.
5. Add the following two tags inside the shared layout's `<head>` section:

```html
<script src="/reviewlayer/embed.js" data-project="default" data-lang="en" defer></script>
<meta name="robots" content="noindex,nofollow">
```

The `embed.js` call should be placed in `<head>` and keep its `defer` attribute. It derives the installation directory from its own `src` and loads CSS, modules, and API automatically. Do not provide the current page or API address.

The `<meta name="robots" content="noindex,nofollow">` tag should appear directly below the script call on every prototype page, preferably through one shared layout. It asks search engines not to index the page or follow its links. It is not access control — anyone who knows the address can still open the prototype.

Example for a separate project with Polish forced:

```html
<script src="/reviewlayer/embed.js" data-project="shop-redesign" data-lang="pl" defer></script>
<meta name="robots" content="noindex,nofollow">
```

The parent repository's `index.html` is only an integration test page. The production application is entirely contained in `reviewlayer/`.

## URLs, pages, and SPAs

The same script serves every page. A page key contains protocol, host, port, pathname, sorted query, and hash. Ports, paths, regular query values, and hash routes remain distinct. A trailing slash outside the root path is normalized.

Technical parameters `reviewlayer`, `reviewlayer_action`, `reviewlayer_lang`, `reviewlayer_debug`, and `reviewlayer_token` do not affect the key. Thus `/offer?variant=2&reviewlayer=clear` still uses data for `/offer?variant=2`.

The overlay observes `history.pushState`, `history.replaceState`, `popstate`, and `hashchange`. Navigation aborts stale requests, closes the previous page conversation, and fetches only the new route's pins without reloading the document.

## Language

English is the default ReviewLayer language. `data-lang` accepts `auto`, `pl`, or `en`; omitting the attribute also starts the interface in English. In `auto`, precedence is saved preference, `html[lang]`, `navigator.languages`, `navigator.language`, then English. Explicit `pl` or `en` wins on startup. Language can be changed in settings without reload and is stored in `localStorage`.

Shared files `assets/i18n/pl.json` and `assets/i18n/en.json` supply copy for both the overlay and diagnostics.

## Projects and configuration

`data-project` separates multiple prototypes in one installation. Letters, digits, dots, `_`, and `-` are allowed, up to 64 characters. Pin numbering is per project.

`config.php` returns a PHP array. Important options:

- `PROJECT_ACCESS_CODE` — optional project code, used when `ALLOW_GUESTS` is `false`.
- `ADMIN_ACCESS_CODE` — optional code for backups, clearing, and administrative deletion. `ALLOW_ADMIN_WITHOUT_CODE` controls behavior when no code exists; authors may still delete their own entries when the corresponding options are enabled.
- `ALLOW_GUESTS` — access without a project code.
- `ALLOW_AUTHOR_DELETE_OWN_MESSAGES` and `ALLOW_AUTHOR_DELETE_OWN_PINS` — soft deletion of own data.
- `ALLOW_ADMIN_WITHOUT_CODE` — when `false`, backups and administrative clearing remain disabled until an administrator code is configured.
- `CREATE_BACKUP_BEFORE_PURGE` — automatic export before permanent clearing.
- `STORAGE_MODE` — `auto`, `sqlite`, or `json`.
- `MOBILE_BREAKPOINT` and `DESKTOP_BREAKPOINT` — 600 and 1024 px by default.
- `PERSISTENT_RATE_LIMIT` — persistent request limiting by hashed IP address, resistant to opening new sessions.
- `MAX_PINS_PER_AUTHOR`, `MAX_MESSAGES_PER_PIN`, `MAX_TOTAL_PINS`, and `MAX_TOTAL_MESSAGES` — optional demo limits; `0` disables a limit.
- `MAX_BACKUPS` — maximum retained backup count; `0` disables automatic pruning.
- text limits and the rate-limit window.

The simplest prototype configuration is:

```php
'PROJECT_ACCESS_CODE' => '',
'ADMIN_ACCESS_CODE' => 'enter-your-own-code-here',
'ALLOW_GUESTS' => true,
```

The code exists only in server-executed `config.php`. Never place it in `embed.js`, the repository, or documentation. A project code entered in settings is kept only in `sessionStorage`. The frontend does not store the administrator code. Legacy `PROJECT_ACCESS_CODE_HASH` and `ADMIN_ACCESS_CODE_HASH` fields remain optionally supported; when configured, a hash takes precedence over its plaintext counterpart.

## Working with pins

The first comment form asks for a name. The name and a random author UUID are stored in `localStorage`; the name remains editable in settings.

Each new commenter in a project receives the next color from a ten-color palette; the eleventh commenter reuses the first color. Pins and author badges keep that assignment, resolved pins use a less saturated companion color, and Settings lists all registered project commenters. Changing a display name does not change the assigned color.

1. Select “Add pin”.
2. Pick a point on the page. Highlighting belongs to the overlay and does not modify the prototype element.
3. Enter the first comment and save. Cancel or `Escape` removes the temporary pin.
4. Select a saved pin to open its conversation, reply, change status, delete a message, or delete the complete pin.
5. The refresh button manually reloads the active conversation.
6. “Locate pin” in the conversation header closes the panel, reveals the pin if necessary, scrolls it into view, and briefly highlights it.

Comments render as plain text only. Timestamps originate on the server in UTC and display in local time.

### Anchoring

ReviewLayer stores a stable selector, element fingerprint, 0–1 relative position, element and document geometry, scroll, viewport, and DPR. During restoration it scores selector and fingerprint candidates, then derives position from the current `getBoundingClientRect()`. DOM, size, orientation, and scroll changes schedule updates through `requestAnimationFrame`. If the target disappeared, the document fallback is used and the pin receives an uncertain-anchor marker.

## Visibility and filters

The ReviewLayer toolbar contains “Show/hide pins” and “Add pin”. “Hide pins” does not delete comments or modify the database. The hidden layer disables both `visibility` and `pointer-events`, so it cannot intercept clicks. The toolbar and an open conversation remain available.

The switch between the arrow icons moves the toolbar to the bottom or top of the viewport. The toolbar starts at the bottom and saves the project-specific choice as `reviewlayer:{project}:toolbar-position`.

The preference uses `reviewlayer:{project}:pins-visible`, shared by project pages but separate between projects. `Alt + P` toggles it outside text fields. Adding a pin still works while existing pins are hidden: the temporary pin is visible and saving does not change the global preference.

Filters `All | Mobile | Tablet | Desktop` select pins by their creation viewport. Hiding does not reset the active filter.

The hamburger icon directly before settings opens every pin in the current project, including pins from other pages. Each item shows its number, status, address, and a first-message preview. Selecting it navigates to the correct page and opens the conversation automatically; the temporary `reviewlayer_pin` parameter is then removed from the address.

## Deletion and protected clearing

Regular pin or message deletion sets `deleted_at` (soft delete). A single pin requires the short `DEL` phrase; when no administrator code is configured, the app does not ask for one. Open the administrator panel with:

```text
https://prototype.example.com/?reviewlayer=clear
```

The GET request never deletes data. The panel requires scope, mode, and `DELETE`; the administrator code is required only when configured. The entire installation requires the exact phrase `DELETE ALL REVIEWLAYER DATA`. Closing removes the technical parameter from the address.

Scopes:

- current page — `project_key + page_key`;
- current project — all its pages;
- resolved in project — status `resolved` only;
- all data — every project, always a permanent purge.

Soft delete retains history. Permanent purge physically removes records. Clearing a project does not reset counters for other projects. The backend recanonicalizes `page_url`, compares its key, validates data, verifies the administrator code, logs the event, and uses a transaction or file lock.

## Backup and restore

Settings contains a separate “Backup” section. “Create backup now” writes a complete export of every project into `data/backups/` without deleting or changing data. If an administrator code is configured, the app asks for it before creating the backup.

With `CREATE_BACKUP_BEFORE_PURGE = true`, permanent clearing first writes portable JSON into `data/backups/`. It includes format version, projects and counters, pins, messages, statuses, dates, anchor, viewport, and browser data. A backup failure blocks purge unless the administrator explicitly chooses to continue without it.

Restore replaces current data with the complete backup. First make an extra copy of `data`, enable maintenance mode, and run from the `reviewlayer` directory:

```bash
php tools/restore.php reviewlayer-backup-2026-07-19T143200Z-abc123.json
```

The tool runs only in PHP CLI, accepts only a filename from `data/backups/`, validates its format, and restores the currently selected storage. On hosting without CLI, ask the administrator to run it; no public restore endpoint exists by design.

## SQLite and JSON fallback

In `auto`, the app selects `data/reviewlayer.sqlite` when `pdo_sqlite` exists. Schema and database are created automatically. Multi-record operations use transactions and prepared statements.

Without SQLite it uses `data/reviewlayer.json`, a separate lock file, and `data/admin.log`. Data is written to a temporary file and atomically renamed. JSON suits small prototypes; SQLite handles higher traffic and concurrent commenters better.

## Data protection

Included `.htaccess` files disable listing and deny downloads from `data/` and backups. Reproduce the rule in Nginx/IIS, for example by returning `403` for `/reviewlayer/data/`. Verify externally with a harmless test filename; its response must not expose contents.

The API applies validation, prepared statements, UUID v4, limits, session CSRF, Origin checks, rate limiting, soft delete, constant-time code comparison through `hash_equals`, and consistent JSON responses. These safeguards fit prototype review; they do not replace a complete account and audit system for sensitive data. `noindex, nofollow` limits indexing but is not access control.

## Troubleshooting

- No toolbar: inspect the console, script `src`, JavaScript MIME types, and access to `assets/i18n/*.json`.
- API unavailable: open `/reviewlayer/api/index.php?action=health` and `/reviewlayer/` diagnostics.
- Write failure: fix ownership/permissions for `data/`; do not default to `777`.
- SQLite unavailable: keep `STORAGE_MODE=auto` for JSON or enable `pdo_sqlite`.
- Corrupt JSON: move the damaged file aside, restore the latest backup, and check free disk space.
- Write conflicts: verify `flock`; use SQLite for busier review sessions.
- Invalid code: verify that `config.php` contains the exact same `ADMIN_ACCESS_CODE`; use the new value after changing it.
- Uncertain pin: its element was removed or lost stable identity, so the fallback position is shown.
- SPA data does not switch: the router must update `window.location` through History API or hash.
- Nginx/IIS: `.htaccess` is ignored, so configure the `data` denial in the server.

## Tests

Run static and page-key tests from the project directory:

```bash
node --test reviewlayer/tests/*.test.mjs
```

The complete manual browser matrix is in `tests/acceptance-checklist.md`.

## Complete uninstall

1. Create a backup if data should be retained.
2. Remove the single `embed.js` tag from the shared layout.
3. Delete `reviewlayer/` over FTP. This removes the app, database, JSON, logs, and backups.
4. Optionally remove `reviewlayer:*` keys from browser `localStorage` and `sessionStorage`.

## Limitations

Anchoring withstands common responsive changes and content shifts, but a fully replaced element without stable attributes can fall back to document coordinates. The overlay cannot enter cross-origin iframes or closed Shadow DOM. The default build assumes same-origin installation. JSON mode is for small teams and is not a transactional database replacement under heavy traffic.

# ReviewLayer acceptance checklist

Run the matrix on an authorized test installation with an empty project. Record browser, OS, viewport, storage mode, and result for every pass.

## Installation and isolation

- Upload only `reviewlayer/`, add one `embed.js` tag, and confirm automatic API and CSS discovery.
- Confirm the overlay uses one body host with Shadow DOM and does not change prototype classes, dimensions, scroll height, or layout.
- Test a prototype element with `z-index: 2147483646`; ReviewLayer remains available.
- Confirm no CDN/runtime dependency is requested in the Network panel.
- Run once with SQLite and once with forced JSON storage.

## Page identity and SPA

- Keep separate pins for `/`, `/oferta`, and `/kontakt`.
- Keep separate pins for localhost ports 3000 and 5173.
- Keep `variant=1` and `variant=2` separate; query parameter order alone must not separate pages.
- Confirm `?reviewlayer=clear` does not change the page key.
- Test `pushState`, `replaceState`, back/forward (`popstate`), and hash routing. Only ReviewLayer data reloads; stale requests are aborted.

## Anchoring and responsive behavior

- Add at the top and after scrolling a long page, reload, then scroll both directions.
- Resize 1440 → 1024 → 768 → 390 → 360 px and rotate a phone.
- Insert content above the target and resize the target; the pin follows it.
- Remove the target; the pin uses fallback coordinates and reports an uncertain anchor.
- Verify positions after dynamic DOM updates without continuous full-DOM scanning.

## Conversation

- First use asks for a name and later remembers it; settings can change it.
- Create a pin, add replies from two browsers, refresh manually, resolve, and reopen.
- A message containing `<script>alert(1)</script>` renders as text and never executes.
- Empty and over-limit names/messages are rejected by both UI and API.
- Delete an owned message and pin (soft delete); verify an unrelated author is denied and an administrator succeeds.
- Change SPA route while submitting and confirm stale page data is not displayed.

## Visibility and device filters

- First launch defaults to visible pins.
- “Hide pins” hides all saved pins, closes tooltips, removes active highlighting, and prevents click interception.
- “Show pins” restores positions after scroll without an API request.
- Preference survives reload and page changes, and is separate for another project.
- `Alt + P` works outside forms and does nothing in input, textarea, select, or contenteditable.
- Mobile/Tablet/Desktop filter survives hide/show.
- An open conversation remains accessible and reports hidden pins.
- Adding while hidden shows only the temporary pin; after save, saved pins remain hidden.
- Open the hamburger menu, verify that it lists pins from every project page, then select one from another page and confirm that its conversation opens automatically.

## Administration and backups

- Create a manual backup from settings, confirm that the success message contains its filename, and verify that no pin or message changed.
- Opening `?reviewlayer=clear` never modifies storage.
- Closing the panel removes only ReviewLayer query parameters and changes no data.
- Wrong admin code and wrong confirmation are denied.
- Current-page clearing keeps other pages; project clearing keeps other projects; resolved clearing keeps open pins.
- “All data” accepts only `DELETE ALL REVIEWLAYER DATA` and always purges.
- Soft delete sets timestamps; permanent purge removes records.
- Purge creates a protected JSON backup first. Simulate backup failure and confirm purge stops unless explicit override is checked.
- After clearing, the UI has no stale cache and a new pin can be created immediately.
- Restore a backup with `tools/restore.php` in a disposable installation and compare counts and content.

## Security and failures

- Call mutation API without CSRF, with bad UUID, oversized JSON, path-like IDs, wrong project code, and rapid repeated requests.
- Attempt direct HTTP download of SQLite, JSON, admin log, lock files, and backups; all must be denied.
- Make `data` read-only, corrupt a JSON copy, and return non-JSON from a test proxy; translated errors appear and details remain secret-free.
- Verify project and administrator codes never appear in source, URLs, API responses, console errors, or persistent localStorage.

## Accessibility and browser matrix

- Keyboard-only: toolbar, filters, pins, forms, focus trap, Escape close, and focus return.
- Screen reader: button names, `aria-pressed`, statuses, live success/error messages, and panel headings.
- Reduced motion removes meaningful transitions; contrast and non-color status indicators remain clear.
- Test Blink/Chrome, Gecko/Firefox, and WebKit/Safari where available at 360, 390, 768, 1024, and 1440 px.
- Test mobile on-screen keyboard with the nearly full-screen conversation sheet.

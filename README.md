# ReviewLayer

ReviewLayer is a lightweight feedback tool for website prototypes. Clients can pin comments directly to page elements, discuss changes, track resolved issues, and revisit feedback across pages and devices—all without external services or complex setup.

## Requirements

- PHP 8.0 or newer
- SQLite with PDO (recommended) or writable JSON storage
- write access to `reviewlayer/data` and `reviewlayer/data/backups`
- ReviewLayer served from the same origin as the reviewed website
- optional manual email notifications require the server's ordinary PHP `mail()` transport and Sodium or OpenSSL

## Quick start

1. Copy the `reviewlayer` directory to the website root.
2. Copy `reviewlayer/config.example.php` to `reviewlayer/config.php` and adjust the settings.
3. Add the following elements inside the reviewed page's `<head>`:

```html
<meta name="robots" content="noindex,nofollow">
<script src="/reviewlayer/embed.js" data-project="shop-redesign" data-lang="en" defer></script>
```

The included `index.html` is the product demo and also exercises the live integration.

Email notifications are never automatic. A commenter must verify their address, and every message requires an explicit click; ReviewLayer stores addresses encrypted and applies persistent anti-abuse limits.

## Documentation

- [English documentation](reviewlayer/README_EN.md)
- [Polska dokumentacja](reviewlayer/README_PL.md)

## Distribution

`reviewlayer/config.php`, databases, backups, logs, and other runtime files are intentionally excluded from Git. Never commit real access codes or client feedback.

## License

ReviewLayer is source-available under the custom [ReviewLayer Source-Available License 1.0](LICENSE). It permits use and modification, including client work, but does not permit resale, paid hosted services, or removal of the required attribution. This is not an OSI-approved open-source license.

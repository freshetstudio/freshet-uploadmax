# Freshet Upload Max

_1.0.0_

By [Freshet Studio](https://freshet.studio).

Raise the WordPress upload size limit **without editing server config**. Plug in, activate, forget. Default **64MB**, overridable via a `wp-config.php` constant or an env var.

## Why this exists

The 2MB default cap is a PHP directive (`upload_max_filesize` / `post_max_size`), not a WordPress setting — and `ini_set()` **cannot** change it at runtime (it's `PHP_INI_PERDIR`). Most "increase max upload" plugins call `ini_set()` anyway, or just filter WordPress's *displayed* number, so the server still rejects the file. On too many hosts, editing php.ini yourself is a mission.

This raises the **real** directive using whatever your host reads:

| SAPI | Mechanism | File |
|------|-----------|------|
| FastCGI / PHP-FPM / LiteSpeed | `.user.ini` | `<wp-root>/.user.ini` |
| Apache mod_php | `.htaccess` `php_value` | `<wp-root>/.htaccess` |
| anything else | — | admin notice, no change |

The `.htaccess` path is only ever written when the handler is genuinely mod_php, because `php_value` directives 500 a FastCGI host. The block is marker-delimited (`# BEGIN Freshet Upload Max`), so existing file contents are preserved and cleanly removed on deactivation.

## Not breaking your site

Two failure modes are handled explicitly, because a "just works" plugin must never leave a site worse off:

- **No persistent 500.** `.user.ini` can't cause a fatal (bad directives are logged and ignored). The only 500 vector is `.htaccess` `php_value` under a restrictive `AllowOverride` — which `IfModule` can't guard. So before touching the live file we **pre-flight the same directives in a throwaway subdirectory**: a server that rejects them 500s the probe, not the site, and the live `.htaccess` is never written. Only when the pre-flight can't prove it unsafe do we write — and then still run a **loopback probe** (against the WordPress directory URL, which is the directory the block governs); if it returns ≥500 (or can't be confirmed), we **roll back to the exact previous file** and surface an admin notice pointing you at server config. The site stays up; worst case the limit is simply unchanged.
- **Silent ineffectiveness.** Success is measured, not assumed: we read the *real* directives back (`ini_get`, immune to other plugins filtering WordPress's displayed limit) and compare against the target. If the file wrote but the limit never rose (host ignores `.user.ini`, hard host cap), you get a notice — after a grace window tracking PHP's `.user.ini` cache (`user_ini.cache_ttl`, ~5 min stock) so the cache isn't mistaken for a failure. A renamed `user_ini.filename` is honoured; a disabled (empty) one reports as unsupported instead of pretending.

## Configuration

Default is 64MB. To override, in `wp-config.php`:

```php
define( 'FRESHET_UPLOADMAX_MB', 128 );
```

or as an environment variable:

```
FRESHET_UPLOADMAX_MB=128
```

Constant wins over env var; both win over the default. Clamped to 1–2048 (MB).

## What it writes

For a 64MB target on a `.user.ini` host:

```ini
; BEGIN Freshet Upload Max
upload_max_filesize = 64M
post_max_size = 72M
memory_limit = 256M
; END Freshet Upload Max
```

`post_max_size` gets +8MB headroom so a full-size file plus its form fields fits (otherwise `$_POST`/`$_FILES` silently arrive empty). `memory_limit` is a floor for image sub-size generation on bigger uploads — raised, never lowered.

## Notes

- `.user.ini` is cached by PHP for up to 5 minutes (`user_ini.cache_ttl`) — the new limit may lag activation by a few minutes.
- Deactivating removes the block and restores the previous limit. The `.user.ini` is deleted if it becomes empty; `.htaccess` (owned by WordPress) is only stripped.
- Scope is deliberately size-only. Time limits (`max_input_time`, `max_execution_time`) for very large/slow uploads stay a server-config concern.
- PHP is not the whole chain: caps in front of it (nginx `client_max_body_size`, proxy/CDN body limits) are out of a plugin's reach and undetectable from inside PHP — disclosed in the readme FAQ rather than papered over.
- Multisite: the written limit is one shared, network-wide file, so writes (and deactivation cleanup) require a network administrator. A per-site activation by a site admin shows a notice and changes nothing.

## Development

No build step — plain PHP. After cloning, arm the content guard once
(`core.hooksPath` lives in `.git/config` and so is never cloned):

```bash
bash .freshet/install-hooks.sh
```

The same check runs in CI on every push, where it cannot be skipped. See
`.freshet/README.md`.

## License

MIT — see `LICENSE`.

## Disclaimer

Built out of personal necessity for the many sites where touching server config is a chore. No dashboard, no settings, no visual indicator — intentional.

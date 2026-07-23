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

## License

MIT — see `LICENSE`.

## Disclaimer

Built out of personal necessity for the many sites where touching server config is a chore. No dashboard, no settings, no visual indicator — intentional.

=== Freshet Upload Max ===
Contributors: kristoffbertram
Tags: upload, max upload size, file size, media, uploads
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Raises the WordPress upload size limit (64MB default) without editing server config. Zero settings. Overridable via a wp-config constant or env var.

== Description ==

The default 2MB upload cap lives in PHP (`upload_max_filesize` / `post_max_size`), and on many hosts editing php.ini is a chore or simply off-limits. Freshet Upload Max raises it for you — no settings screen, no dashboard.

It writes a small, marker-delimited block to the mechanism your host actually reads:

* **FastCGI / PHP-FPM / LiteSpeed** — a `.user.ini` in the WordPress root.
* **Apache mod_php** — a `php_value` block in `.htaccess` (only ever written when the handler is mod_php, so it can't 500 a FastCGI host).

It does **not** fake the fix by only filtering WordPress's displayed limit — that leaves the server rejecting the file with a confusing error. It raises the real PHP directive, or tells you (admin notice) when it couldn't.

It also won't take your site down. On mod_php hosts, an `.htaccess` `php_value` can 500 if `AllowOverride` forbids it — so after writing, the plugin probes the site with a loopback request and, if it broke, restores the file exactly and shows a notice instead. And it verifies the limit *actually* rose (measuring the real runtime value), so a host that silently ignores the change gets flagged rather than failing mysteriously at upload time.

**Default: 64MB.** Override it in `wp-config.php`:

`define( 'FRESHET_UPLOADMAX_MB', 128 );`

…or with an environment variable:

`FRESHET_UPLOADMAX_MB=128`

Deactivating the plugin removes its block and restores the previous limit.

== Installation ==

1. Install and activate. Done — there are no settings. The new limit applies on the next request (PHP caches `.user.ini` for up to 5 minutes: `user_ini.cache_ttl`).

== Frequently Asked Questions ==

= It says the upload limit is still 2MB =

`.user.ini` changes can take up to 5 minutes to apply (PHP's `user_ini.cache_ttl`). If it never updates, your WordPress root may not be writable, or your host may serve PHP through a handler that reads neither `.user.ini` nor `.htaccess` — you'll get an admin notice in that case.

= Can I go higher than 64MB? =

Yes — set `FRESHET_UPLOADMAX_MB` in `wp-config.php` to any value up to 2048 (2GB). Note some hosts impose their own hard ceiling above which nothing in userland can raise it.

= Does it change execution or input time limits? =

No. It only touches size (`upload_max_filesize`, `post_max_size`) plus a `memory_limit` floor for image processing. Very large files on slow connections may also need `max_input_time` / `max_execution_time` raised in server config.

== Changelog ==

= 1.0.0 =
* Initial release.

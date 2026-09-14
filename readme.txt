=== Freshet Upload Max ===
Contributors: kristoffbertram
Tags: upload, max upload size, file size, media, uploads
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.1
License: MIT
License URI: https://opensource.org/licenses/MIT

Raises the WordPress upload size limit (64MB default) without editing server config. Zero settings. Overridable via a wp-config constant or env var.

== Description ==

The default 2MB upload cap lives in PHP (`upload_max_filesize` / `post_max_size`), and on many hosts editing php.ini is a chore or simply off-limits. Freshet Upload Max raises it for you — no settings screen, no dashboard.

It writes a small, marker-delimited block to the mechanism your host actually reads:

* **FastCGI / PHP-FPM / LiteSpeed** — a `.user.ini` in the WordPress root.
* **Apache mod_php** — a `php_value` block in `.htaccess` (only ever written when the handler is mod_php, so it can't 500 a FastCGI host).

It does **not** fake the fix by only filtering WordPress's displayed limit — that leaves the server rejecting the file with a confusing error. It raises the real PHP directive, or tells you (admin notice) when it couldn't.

It also won't take your site down. On mod_php hosts, an `.htaccess` `php_value` can 500 if `AllowOverride` forbids it — so the plugin first proves the directives are accepted in a throwaway subdirectory (a server that rejects them is detected before the live `.htaccess` is ever touched), then probes the site with a loopback request after writing and, if anything broke, restores the file exactly and shows a notice instead. And it verifies the limit *actually* rose (measuring the real runtime value), so a host that silently ignores the change gets flagged rather than failing mysteriously at upload time.

**Default: 64MB.** Override it in `wp-config.php`:

`define( 'FRESHET_UPLOADMAX_MB', 128 );`

…or with an environment variable:

`FRESHET_UPLOADMAX_MB=128`

Deactivating the plugin removes its block and restores the previous limit.

Part of the Freshet plugin suite. Full documentation: [freshet.studio/docs](https://freshet.studio/docs).

== Installation ==

1. Install and activate. Done — there are no settings. The new limit applies on the next request (PHP caches `.user.ini` for up to 5 minutes: `user_ini.cache_ttl`).

== Frequently Asked Questions ==

= It says the upload limit is still 2MB =

`.user.ini` changes can take up to 5 minutes to apply (PHP's `user_ini.cache_ttl`). If it never updates, your WordPress root may not be writable, or your host may serve PHP through a handler that reads neither `.user.ini` nor `.htaccess` — you'll get an admin notice in that case.

= Can I go higher than 64MB? =

Yes — set `FRESHET_UPLOADMAX_MB` in `wp-config.php` to any value up to 2048 (2GB). Note some hosts impose their own hard ceiling above which nothing in userland can raise it.

= The limit went up, but large uploads still fail =

PHP's limit is not the only one in the chain. A web server or proxy in front of PHP — nginx's `client_max_body_size`, a CDN or load balancer body cap — rejects big requests before PHP ever sees them, and no plugin can raise or even detect those. If the Media screen shows the new limit but uploads over a certain size still fail (often a 413 error), raise the cap in that layer too.

= Does it work on multisite? =

The limit it writes is one shared, network-wide setting (a single file in the WordPress root), so it's network-admin territory: activate it network-wide as a network administrator. A site admin activating it on one subsite gets a notice instead of a write. Note the network's own "Max upload file size" setting (Network Settings) also caps uploads independently of PHP.

= WordPress lives in its own subdirectory — is that supported? =

Yes for the Media library and everything in wp-admin, which is where WordPress uploads happen. One edge: front-end upload forms (page-builder or form-plugin uploads posted to a page URL) execute from the parent directory in that layout and won't see the raised limit.

= Does it change execution or input time limits? =

No. It only touches size (`upload_max_filesize`, `post_max_size`) plus a `memory_limit` floor for image processing (a floor only — a host already running higher keeps its higher value). Very large files on slow connections may also need `max_input_time` / `max_execution_time` raised in server config.

== Screenshots ==

1. The Media upload screen reporting the raised limit — 64 MB in place of the host's default — with nothing configured.

== Changelog ==

= 1.0.1 =
* File writes now go through the WordPress filesystem API where the host allows it, falling back to a direct write otherwise — never asking for FTP credentials to raise an upload limit.
* No behaviour change: the managed block, the locked read-modify-write and the .htaccess rollback are unchanged.
* Deleting the plugin now removes its own options, including the record of a block deactivation could not clear.

= 1.0.0 =
* Initial release.

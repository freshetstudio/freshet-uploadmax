<?php
/**
 * Plugin Name:       Freshet Upload Max
 * Plugin URI:        https://freshet.studio
 * Description:       Raises the WordPress upload size limit (default 64MB) without editing server config, by writing a managed .user.ini or .htaccess block. Overridable via a wp-config constant or env var.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Freshet Studio
 * Author URI:        https://freshet.studio
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       freshet-uploadmax
 */

defined('ABSPATH') || exit;

// Single version source; keep in sync with the header + readme stable tag
// (portfolio convention, same as freshet-editjump / freshet-feeds).
define('FRESHET_UPLOADMAX_VERSION', '1.0.0');

// Marker used to delimit our managed block in .user.ini / .htaccess.
define('FRESHET_UPLOADMAX_MARKER', 'Freshet Upload Max');

/**
 * Resolve the target upload limit in whole megabytes.
 *
 * Precedence: FRESHET_UPLOADMAX_MB constant (define it in wp-config.php) →
 * FRESHET_UPLOADMAX_MB environment variable → 64MB default. Clamped to a sane
 * 1..2048 range so a typo can't write a nonsense directive.
 */
function freshet_uploadmax_limit_mb(): int {
	$default = 64;

	if ( defined('FRESHET_UPLOADMAX_MB') ) {
		$mb = (int) FRESHET_UPLOADMAX_MB;
	} else {
		$env = getenv('FRESHET_UPLOADMAX_MB');
		$mb  = ( false !== $env && '' !== $env ) ? (int) $env : $default;
	}

	if ( $mb < 1 ) {
		$mb = $default;
	}
	if ( $mb > 2048 ) {
		$mb = 2048;
	}

	return $mb;
}

/**
 * Decide which mechanism actually works on this SAPI.
 *
 * upload_max_filesize / post_max_size are PHP_INI_PERDIR — ini_set() can't
 * touch them at runtime, so we must write a per-directory ini source:
 *   - FastCGI / PHP-FPM / LiteSpeed → .user.ini (harmless where unread)
 *   - Apache mod_php               → .htaccess php_value (500s under FastCGI,
 *                                     so only ever written for apache2handler)
 * Anything else we can't safely raise → 'unsupported'.
 */
function freshet_uploadmax_mechanism(): string {
	switch ( php_sapi_name() ) {
		case 'fpm-fcgi':
		case 'cgi-fcgi':
		case 'litespeed':
			return 'userini';
		case 'apache2handler':
			return 'htaccess';
		default:
			return 'unsupported';
	}
}

/**
 * Directive body for a .user.ini (php.ini syntax, ';' comments).
 *
 * post_max_size gets headroom over upload_max_filesize so a full-size file
 * plus its multipart form fields still fits under post_max_size (otherwise
 * $_POST/$_FILES arrive empty with no obvious error). memory_limit is a floor
 * — raised, never lowered — so sub-size image generation on bigger uploads
 * doesn't OOM.
 */
function freshet_uploadmax_userini_body( int $mb ): string {
	$post = $mb + 8;
	$mem  = max(256, $mb);

	return "upload_max_filesize = {$mb}M\n"
		. "post_max_size = {$post}M\n"
		. "memory_limit = {$mem}M";
}

/**
 * Directive body for an .htaccess block (mod_php php_value, '#' comments).
 * Guarded by IfModule so it degrades to a no-op rather than a 500 if the
 * handler ever changes out from under us.
 */
function freshet_uploadmax_htaccess_body( int $mb ): string {
	$post = $mb + 8;
	$mem  = max(256, $mb);

	$values = "php_value upload_max_filesize {$mb}M\n"
		. "php_value post_max_size {$post}M\n"
		. "php_value memory_limit {$mem}M";

	return "<IfModule mod_php.c>\n{$values}\n</IfModule>\n"
		. "<IfModule mod_php7.c>\n{$values}\n</IfModule>";
}

/**
 * Insert/update our managed block in $file, preserving everything else.
 * Idempotent: returns true without writing when the file already matches.
 * $comment is ';' for ini files, '#' for .htaccess.
 */
function freshet_uploadmax_write_block( string $file, string $comment, string $body ): bool {
	$begin = "{$comment} BEGIN " . FRESHET_UPLOADMAX_MARKER;
	$end   = "{$comment} END " . FRESHET_UPLOADMAX_MARKER;

	$existing = is_readable($file) ? (string) file_get_contents($file) : '';

	// Strip any prior copy of our block (with surrounding blank lines).
	$pattern = '/\R*' . preg_quote($begin, '/') . '.*?' . preg_quote($end, '/') . '\R*/s';
	$other   = rtrim( (string) preg_replace($pattern, "\n", $existing) );

	$block = "{$begin}\n{$body}\n{$end}\n";
	$new   = ( '' === $other ) ? $block : "{$other}\n\n{$block}";

	if ( $new === $existing ) {
		return true; // already correct — no disk write
	}

	return false !== @file_put_contents($file, $new, LOCK_EX);
}

/**
 * Remove our managed block from $file. Deletes the file when it created it and
 * nothing else remains (only for files we own, i.e. $delete_if_empty).
 */
function freshet_uploadmax_remove_block( string $file, string $comment, bool $delete_if_empty ): void {
	if ( ! is_readable($file) ) {
		return;
	}

	$begin   = "{$comment} BEGIN " . FRESHET_UPLOADMAX_MARKER;
	$end     = "{$comment} END " . FRESHET_UPLOADMAX_MARKER;
	$pattern = '/\R*' . preg_quote($begin, '/') . '.*?' . preg_quote($end, '/') . '\R*/s';

	$existing = (string) file_get_contents($file);
	$cleaned  = rtrim( (string) preg_replace($pattern, "\n", $existing) );

	if ( '' === $cleaned && $delete_if_empty ) {
		@unlink($file);
		return;
	}

	if ( $cleaned !== rtrim($existing) ) {
		@file_put_contents($file, '' === $cleaned ? '' : $cleaned . "\n", LOCK_EX);
	}
}

/**
 * Apply the limit via the mechanism this host supports. Idempotent; safe to
 * call on every admin load (self-heal) as well as on activation.
 */
function freshet_uploadmax_apply(): void {
	$mb = freshet_uploadmax_limit_mb();

	switch ( freshet_uploadmax_mechanism() ) {
		case 'userini':
			$ok = freshet_uploadmax_write_block(ABSPATH . '.user.ini', ';', freshet_uploadmax_userini_body($mb));
			update_option('freshet_uploadmax_status', $ok ? 'ok' : 'write_failed', false);
			break;
		case 'htaccess':
			$ok = freshet_uploadmax_write_block(ABSPATH . '.htaccess', '#', freshet_uploadmax_htaccess_body($mb));
			update_option('freshet_uploadmax_status', $ok ? 'ok' : 'write_failed', false);
			break;
		default:
			update_option('freshet_uploadmax_status', 'unsupported', false);
	}
}

// Write on activation so it takes effect straight away…
register_activation_hook(__FILE__, 'freshet_uploadmax_apply');

// …and self-heal on admin load (catches value changes, a wiped file, or a
// host that only reached FPM after activation). The write is a no-op when the
// block is already correct.
add_action('admin_init', 'freshet_uploadmax_apply');

// Clean up our blocks on deactivation — don't leave a stuck-high limit behind.
register_deactivation_hook(__FILE__, function () {
	freshet_uploadmax_remove_block(ABSPATH . '.user.ini', ';', true);   // ours to delete
	freshet_uploadmax_remove_block(ABSPATH . '.htaccess', '#', false);  // WP owns this file
	delete_option('freshet_uploadmax_status');
});

// Tell an admin when we couldn't actually raise the limit — the whole point is
// that it silently works, so the only notices are the failure cases.
add_action('admin_notices', function () {
	if ( ! current_user_can('manage_options') ) {
		return;
	}

	$status = get_option('freshet_uploadmax_status');
	if ( 'ok' === $status || false === $status ) {
		return;
	}

	if ( 'write_failed' === $status ) {
		$msg = sprintf(
			/* translators: %s: absolute path to the WordPress root. */
			esc_html__('Freshet Upload Max couldn\'t write to %s. Make the WordPress root writable, or set the upload limit in your server config.', 'freshet-uploadmax'),
			'<code>' . esc_html(ABSPATH) . '</code>'
		);
	} else { // unsupported
		$msg = esc_html__('Freshet Upload Max couldn\'t detect a supported PHP handler (FastCGI/FPM or mod_php) on this host, so it left the upload limit unchanged. Raise it in your server config instead.', 'freshet-uploadmax');
	}

	echo '<div class="notice notice-warning"><p>' . wp_kses($msg, ['code' => []]) . '</p></div>';
});

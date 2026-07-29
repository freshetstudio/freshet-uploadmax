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
 * Write $contents to $file, reporting a failure instead of suppressing it.
 *
 * Deliberately no '@': a plugin whose entire job is writing a config file must
 * not hide whether the write worked. The failure this host actually produces —
 * a read-only WordPress root — is caught by the writability check, so the
 * common case returns false without a warning, and neither path can fatal.
 *
 * @return bool True when the file now holds $contents.
 */
function freshet_uploadmax_put( string $file, string $contents ): bool {
	if ( ! is_writable( file_exists($file) ? $file : dirname($file) ) ) {
		return false;
	}

	return false !== file_put_contents($file, $contents, LOCK_EX);
}

/**
 * Delete $file, reporting a failure instead of suppressing it. Same reasoning
 * as freshet_uploadmax_put(): unlink() needs the *directory* writable.
 */
function freshet_uploadmax_unlink( string $file ): bool {
	if ( ! is_writable( dirname($file) ) ) {
		return false;
	}

	return unlink($file);
}

/**
 * Insert/update our managed block in $file, preserving everything else.
 * Idempotent. $comment is ';' for ini files, '#' for .htaccess.
 *
 * @return string 'noop' (already correct), 'written' (changed), 'failed' (write error).
 */
function freshet_uploadmax_write_block( string $file, string $comment, string $body ): string {
	$begin = "{$comment} BEGIN " . FRESHET_UPLOADMAX_MARKER;
	$end   = "{$comment} END " . FRESHET_UPLOADMAX_MARKER;

	$existing = is_readable($file) ? (string) file_get_contents($file) : '';

	// Strip any prior copy of our block (with surrounding blank lines).
	$pattern = '/\R*' . preg_quote($begin, '/') . '.*?' . preg_quote($end, '/') . '\R*/s';
	$other   = rtrim( (string) preg_replace($pattern, "\n", $existing) );

	$block = "{$begin}\n{$body}\n{$end}\n";
	$new   = ( '' === $other ) ? $block : "{$other}\n\n{$block}";

	if ( $new === $existing ) {
		return 'noop'; // already correct — no disk write
	}

	return freshet_uploadmax_put($file, $new) ? 'written' : 'failed';
}

/**
 * Remove our managed block from $file. Deletes the file when it created it and
 * nothing else remains (only for files we own, i.e. $delete_if_empty).
 *
 * @return string 'noop' (nothing of ours there), 'removed' (changed), 'failed' (write error).
 */
function freshet_uploadmax_remove_block( string $file, string $comment, bool $delete_if_empty ): string {
	if ( ! is_readable($file) ) {
		return 'noop';
	}

	$begin   = "{$comment} BEGIN " . FRESHET_UPLOADMAX_MARKER;
	$end     = "{$comment} END " . FRESHET_UPLOADMAX_MARKER;
	$pattern = '/\R*' . preg_quote($begin, '/') . '.*?' . preg_quote($end, '/') . '\R*/s';

	$existing = (string) file_get_contents($file);
	$cleaned  = rtrim( (string) preg_replace($pattern, "\n", $existing) );

	if ( '' === $cleaned && $delete_if_empty ) {
		return freshet_uploadmax_unlink($file) ? 'removed' : 'failed';
	}

	if ( $cleaned !== rtrim($existing) ) {
		return freshet_uploadmax_put($file, '' === $cleaned ? '' : $cleaned . "\n") ? 'removed' : 'failed';
	}

	return 'noop';
}

/**
 * Drop $file from the "couldn't clean this up" record. A successful write to
 * the same file supersedes the leftover block that record's notice is about.
 */
function freshet_uploadmax_clear_cleanup_failure( string $file ): void {
	$failed = get_option('freshet_uploadmax_cleanup_failed');

	if ( ! is_array($failed) || ! in_array($file, $failed, true) ) {
		return;
	}

	$remaining = array_values( array_diff($failed, [$file]) );

	if ( $remaining ) {
		update_option('freshet_uploadmax_cleanup_failed', $remaining, false);
	} else {
		delete_option('freshet_uploadmax_cleanup_failed');
	}
}

/**
 * Write the .htaccess block, then verify the new php_value directives didn't
 * take the site down. IfModule guards can't catch an AllowOverride that forbids
 * php_value — that 500s every request — so we probe with a loopback GET and roll
 * back to the exact previous contents if the site stops responding.
 *
 * @return string 'noop' | 'written' | 'failed' | 'unsafe' | 'rollback_failed'
 */
function freshet_uploadmax_apply_htaccess( int $mb ): string {
	$file   = ABSPATH . '.htaccess';
	$before = is_readable($file) ? (string) file_get_contents($file) : null;

	$result = freshet_uploadmax_write_block($file, '#', freshet_uploadmax_htaccess_body($mb));
	if ( 'written' !== $result ) {
		return $result; // 'noop' (already verified) or 'failed'
	}

	if ( freshet_uploadmax_site_responds() ) {
		return 'written';
	}

	// The directives broke Apache (or we couldn't confirm they didn't). Restore
	// and give up on .htaccess — better an unchanged limit than a dead site.
	// A restore that itself fails is the one outcome an admin must be told about:
	// our block is live on a server that rejects it.
	if ( null === $before ) {
		$restored = 'failed' !== freshet_uploadmax_remove_block($file, '#', true);
	} else {
		$restored = freshet_uploadmax_put($file, $before);
	}

	return $restored ? 'unsafe' : 'rollback_failed';
}

/**
 * Loopback probe: does the front page still return < 500? A WP_Error (loopback
 * blocked, timeout) is treated as "not safe" — for .htaccess we stay
 * conservative, since the failure mode we're guarding against is a total outage.
 */
function freshet_uploadmax_site_responds(): bool {
	$resp = wp_remote_get( home_url('/'), [
		'timeout'     => 5,
		'redirection' => 0,
		'sslverify'   => false, // loopback may hit a self-signed/local cert
	] );

	if ( is_wp_error($resp) ) {
		return false;
	}
	return (int) wp_remote_retrieve_response_code($resp) < 500;
}

/**
 * Record whether the limit *actually* rose, not just whether a file was written.
 * Measures the real runtime ceiling (min of upload_max_filesize / post_max_size),
 * with a grace window for PHP's .user.ini cache (user_ini.cache_ttl, ~300s) so we
 * don't false-alarm in the minutes right after applying.
 */
function freshet_uploadmax_evaluate( int $target ): void {
	$effective = (int) floor( wp_max_upload_size() / MB_IN_BYTES );

	if ( $effective >= $target ) {
		update_option('freshet_uploadmax_status', 'ok', false);
		return;
	}

	$applied = get_option('freshet_uploadmax_applied');
	$age     = is_array($applied) ? ( time() - (int) ( $applied['time'] ?? 0 ) ) : 0;

	// Past the cache window and still low → the host is ignoring us or caps lower.
	update_option('freshet_uploadmax_status', $age > 360 ? 'not_effective' : 'pending', false);
}

/**
 * Apply the limit via the mechanism this host supports. Idempotent; safe to
 * call on every admin load (self-heal) as well as on activation.
 */
function freshet_uploadmax_apply(): void {
	$mechanism = freshet_uploadmax_mechanism();

	if ( 'unsupported' === $mechanism ) {
		update_option('freshet_uploadmax_status', 'unsupported', false);
		return;
	}

	// Once .htaccess has proven to break this host, stop retrying (each retry is
	// a loopback request). Deactivating clears the status, so reactivation retries.
	if ( in_array( get_option('freshet_uploadmax_status'), ['htaccess_unsafe', 'rollback_failed'], true ) ) {
		return;
	}

	$mb   = freshet_uploadmax_limit_mb();
	$file = ( 'userini' === $mechanism ) ? ABSPATH . '.user.ini' : ABSPATH . '.htaccess';

	if ( 'userini' === $mechanism ) {
		$result = freshet_uploadmax_write_block($file, ';', freshet_uploadmax_userini_body($mb));
	} else {
		$result = freshet_uploadmax_apply_htaccess($mb);
	}

	if ( 'failed' === $result ) {
		update_option('freshet_uploadmax_status', 'write_failed', false);
		return;
	}
	if ( 'unsafe' === $result ) {
		update_option('freshet_uploadmax_status', 'htaccess_unsafe', false);
		return;
	}
	if ( 'rollback_failed' === $result ) {
		update_option('freshet_uploadmax_status', 'rollback_failed', false);
		return;
	}
	if ( 'written' === $result ) {
		update_option('freshet_uploadmax_applied', ['mb' => $mb, 'time' => time()], false);
	}

	// This file is ours and correct again, so any leftover-block warning a failed
	// deactivation left behind for it no longer describes reality.
	freshet_uploadmax_clear_cleanup_failure($file);

	freshet_uploadmax_evaluate($mb);
}

/**
 * The admin_init entry point — everything the hook needs to be true before the
 * self-heal is allowed to touch the filesystem.
 *
 * admin_init also fires on admin-ajax.php and admin-post.php, both reachable
 * *unauthenticated*, so without this an anonymous request reaches the write
 * path. Nothing there is exploitable — the content is constant and clamped, and
 * the write is a no-op when the block already matches — but an unauthenticated
 * filesystem write is not a shape worth shipping, or reviewing.
 */
function freshet_uploadmax_maybe_apply(): void {
	if ( wp_doing_ajax() || wp_doing_cron() ) {
		return;
	}

	if ( defined('REST_REQUEST') && REST_REQUEST ) {
		return;
	}

	// A real administrator on a real admin screen — the same capability that
	// gates the notices reporting what this write did.
	if ( ! is_admin() || ! current_user_can('manage_options') ) {
		return;
	}

	freshet_uploadmax_apply();
}

// Write on activation so it takes effect straight away…
register_activation_hook(__FILE__, 'freshet_uploadmax_apply');

// …and self-heal on admin load (catches value changes, a wiped file, or a
// host that only reached FPM after activation). The write is a no-op when the
// block is already correct, and gated — see freshet_uploadmax_maybe_apply().
add_action('admin_init', 'freshet_uploadmax_maybe_apply');

// Clean up our blocks on deactivation — don't leave a stuck-high limit behind.
register_deactivation_hook(__FILE__, function () {
	$failed = [];

	if ( 'failed' === freshet_uploadmax_remove_block(ABSPATH . '.user.ini', ';', true) ) {   // ours to delete
		$failed[] = ABSPATH . '.user.ini';
	}
	if ( 'failed' === freshet_uploadmax_remove_block(ABSPATH . '.htaccess', '#', false) ) {  // WP owns this file
		$failed[] = ABSPATH . '.htaccess';
	}

	delete_option('freshet_uploadmax_status');
	delete_option('freshet_uploadmax_applied');

	// Deactivation is the one failure that outlives our own admin_notices hook —
	// once we're inactive nothing of ours renders — so it is recorded rather than
	// shown. The block is still on disk raising the limit, and the notice fires
	// the moment the plugin loads again.
	if ( $failed ) {
		update_option('freshet_uploadmax_cleanup_failed', $failed, false);
	} else {
		delete_option('freshet_uploadmax_cleanup_failed');
	}
});

// Tell an admin when we couldn't actually raise the limit — the whole point is
// that it silently works, so the only notices are the failure cases.
add_action('admin_notices', function () {
	if ( ! current_user_can('manage_options') ) {
		return;
	}

	// A block a previous deactivation couldn't remove is still raising the limit
	// on disk, and only a human can clear it — reported independently of $status,
	// which deactivation resets.
	$leftover = get_option('freshet_uploadmax_cleanup_failed');
	if ( is_array($leftover) && $leftover ) {
		$notice = sprintf(
			/* translators: %s: absolute path(s) to the file(s) still holding the block. */
			esc_html__('Freshet Upload Max couldn\'t remove its managed block from %s. Delete the block between the BEGIN/END Freshet Upload Max markers by hand, or the raised limit stays in place.', 'freshet-uploadmax'),
			'<code>' . esc_html( implode(', ', $leftover) ) . '</code>'
		);
		echo '<div class="notice notice-warning"><p>' . wp_kses($notice, ['code' => []]) . '</p></div>';
	}

	// Silent on success ('ok'), while a fresh .user.ini cache warms ('pending'),
	// and before we've run ('false'). Notices are the genuine failure cases only.
	$status = get_option('freshet_uploadmax_status');

	switch ( $status ) {
		case 'write_failed':
			$msg = sprintf(
				/* translators: %s: absolute path to the WordPress root. */
				esc_html__('Freshet Upload Max couldn\'t write to %s. Make the WordPress root writable, or set the upload limit in your server config.', 'freshet-uploadmax'),
				'<code>' . esc_html(ABSPATH) . '</code>'
			);
			break;
		case 'unsupported':
			$msg = esc_html__('Freshet Upload Max couldn\'t detect a supported PHP handler (FastCGI/FPM or mod_php) on this host, so it left the upload limit unchanged. Raise it in your server config instead.', 'freshet-uploadmax');
			break;
		case 'htaccess_unsafe':
			$msg = esc_html__('Freshet Upload Max\'s .htaccess directives were rejected by your server (usually a restrictive AllowOverride), so the change was rolled back to keep the site online. Raise the upload limit in your server config instead.', 'freshet-uploadmax');
			break;
		case 'rollback_failed':
			$msg = sprintf(
				/* translators: %s: absolute path to the .htaccess file. */
				esc_html__('Freshet Upload Max\'s .htaccess directives were rejected by your server and the previous %s could not be restored. Remove the block between the BEGIN/END Freshet Upload Max markers by hand — the site may be returning errors until you do.', 'freshet-uploadmax'),
				'<code>' . esc_html(ABSPATH . '.htaccess') . '</code>'
			);
			break;
		case 'not_effective':
			$msg = esc_html__('Freshet Upload Max wrote the config, but the upload limit hasn\'t increased — your host may ignore .user.ini or enforce a lower hard cap. Raise it in your server config instead.', 'freshet-uploadmax');
			break;
		default: // ok, pending, false
			return;
	}

	echo '<div class="notice notice-warning"><p>' . wp_kses($msg, ['code' => []]) . '</p></div>';
});

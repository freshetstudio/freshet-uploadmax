<?php
/**
 * Plugin Name:       Freshet Upload Max
 * Description:       Raises the WordPress upload size limit (default 64MB) without editing server config, by writing a managed .user.ini or .htaccess block. Overridable via a wp-config constant or env var.
 * Version:           1.0.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Freshet Studio
 * Author URI:        https://freshet.studio
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       freshet-uploadmax
 * Domain Path:       /languages
 */

defined('ABSPATH') || exit;

// Single version source; keep in sync with the header + readme stable tag
// (portfolio convention, same as freshet-editjump / freshet-feeds).
define('FRESHET_UPLOADMAX_VERSION', '1.0.1');

// Marker used to delimit our managed block in .user.ini / .htaccess.
define('FRESHET_UPLOADMAX_MARKER', 'Freshet Upload Max');

// Translations shipped inside the plugin's own /languages need this call —
// without a custom path the textdomain registry only looks in WP_LANG_DIR, so
// wp.org-delivered translations load either way but a bundled .mo never would.
// On init: nothing here translates earlier.
add_action('init', function () {
	load_plugin_textdomain('freshet-uploadmax', false, dirname(plugin_basename(__FILE__)) . '/languages');
});

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
 * The per-directory ini filename PHP is actually configured to read
 * (user_ini.filename — '.user.ini' on stock builds). Hosts can rename it, in
 * which case a hardcoded '.user.ini' writes a file PHP never reads while we
 * report progress — or empty it, which disables the mechanism entirely.
 */
function freshet_uploadmax_userini_name(): string {
	return trim( (string) ini_get('user_ini.filename') );
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
			// An emptied user_ini.filename disables per-directory ini files —
			// nothing we could write would ever be read.
			return '' === freshet_uploadmax_userini_name() ? 'unsupported' : 'userini';
		case 'apache2handler':
			return 'htaccess';
		default:
			return 'unsupported';
	}
}

/**
 * memory_limit value for the managed block: a genuine floor, never a
 * reduction. A fixed max(256, $mb) would *lower* a host already running
 * higher (a global 512M becomes a per-directory 256M), so the current runtime
 * value joins the max. '-1' (unlimited) passes through unchanged rather than
 * being "raised" to a number.
 */
function freshet_uploadmax_memory_value( int $mb ): string {
	$current = wp_convert_hr_to_bytes( (string) ini_get('memory_limit') );

	if ( $current < 0 ) {
		return '-1'; // already unlimited — keep it that way
	}

	$current_mb = (int) ceil( $current / MB_IN_BYTES );

	return max(256, $mb, $current_mb) . 'M';
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
	$mem  = freshet_uploadmax_memory_value($mb);

	return "upload_max_filesize = {$mb}M\n"
		. "post_max_size = {$post}M\n"
		. "memory_limit = {$mem}";
}

/**
 * Directive body for an .htaccess block (mod_php php_value, '#' comments).
 * Guarded by IfModule so it degrades to a no-op rather than a 500 if the
 * handler ever changes out from under us.
 */
function freshet_uploadmax_htaccess_body( int $mb ): string {
	$post = $mb + 8;
	$mem  = freshet_uploadmax_memory_value($mb);

	$values = "php_value upload_max_filesize {$mb}M\n"
		. "php_value post_max_size {$post}M\n"
		. "php_value memory_limit {$mem}";

	return "<IfModule mod_php.c>\n{$values}\n</IfModule>\n"
		. "<IfModule mod_php7.c>\n{$values}\n</IfModule>";
}

/**
 * The initialised WP_Filesystem, or null when it will not come up.
 *
 * wp.org asks plugins to write through WP_Filesystem, and where it works we
 * use it. But it is only *direct* when WordPress says it is: on a host that
 * resolves to the FTP or SSH2 transport, WP_Filesystem() returns false without
 * stored credentials, and the usual remedy — request_filesystem_credentials() —
 * would put an FTP login in front of a plugin whose entire job is raising an
 * upload limit. So we never prompt. No credentials, no WP_Filesystem, and the
 * caller falls back to the direct PHP call, which is what a read-only root
 * already reports cleanly through the writability check.
 *
 * Called with no arguments, WP_Filesystem() never prompts on its own: it picks
 * a method, constructs the transport and returns false if connect() fails.
 *
 * @return WP_Filesystem_Base|null
 */
function freshet_uploadmax_fs() {
	global $wp_filesystem;

	static $ready = null;

	if ( null === $ready ) {
		if ( ! function_exists('WP_Filesystem') ) {
			$include = ABSPATH . 'wp-admin/includes/file.php';
			if ( ! is_readable($include) ) {
				return null; // not cached — a later request may be an admin one
			}
			require_once $include;
		}

		$ready = (bool) WP_Filesystem();
	}

	return ( $ready && $wp_filesystem instanceof WP_Filesystem_Base ) ? $wp_filesystem : null;
}

/**
 * Writability of $path through WP_Filesystem where it is available, and
 * through the direct call where it is not (see freshet_uploadmax_fs()). Every
 * writability pre-check in this file goes through here, so the file carries
 * exactly one direct is_writable().
 */
function freshet_uploadmax_is_writable( string $path ): bool {
	$fs = freshet_uploadmax_fs();

	if ( $fs ) {
		return (bool) $fs->is_writable($path);
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- WP_Filesystem would not initialise here (see freshet_uploadmax_fs()); this is its documented direct fallback, and pre-checking is what keeps a read-only root warning-free.
	return is_writable($path);
}

/**
 * Write $contents to $file, reporting a failure instead of suppressing it.
 *
 * Deliberately no '@': a plugin whose entire job is writing a config file must
 * not hide whether the write worked. The failure this host actually produces —
 * a read-only WordPress root — is caught by the writability check, so the
 * common case returns false without a warning, and neither path can fatal.
 *
 * This one carries no locking contract — the callers are the throwaway
 * preflight probe and the .htaccess rollback restore, both whole-file writes —
 * so it goes through WP_Filesystem, and only falls back to the direct write
 * when that will not initialise.
 *
 * @return bool True when the file now holds $contents.
 */
function freshet_uploadmax_put( string $file, string $contents ): bool {
	if ( ! freshet_uploadmax_is_writable( file_exists($file) ? $file : dirname($file) ) ) {
		return false;
	}

	$fs = freshet_uploadmax_fs();
	if ( $fs ) {
		return (bool) $fs->put_contents($file, $contents);
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- WP_Filesystem would not initialise here (see freshet_uploadmax_fs()); direct fallback, LOCK_EX so a concurrent reader never sees a half-written file.
	return false !== file_put_contents($file, $contents, LOCK_EX);
}

/**
 * Delete $file, reporting a failure instead of suppressing it. Same reasoning
 * as freshet_uploadmax_put(): deleting needs the *directory* writable, and the
 * deletion itself goes through WP_Filesystem wherever that will initialise.
 */
function freshet_uploadmax_unlink( string $file ): bool {
	if ( ! freshet_uploadmax_is_writable( dirname($file) ) ) {
		return false;
	}

	$fs = freshet_uploadmax_fs();
	if ( $fs ) {
		return (bool) $fs->delete($file, false, 'f');
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- WP_Filesystem would not initialise here (see freshet_uploadmax_fs()); wp_delete_file() returns void, so it cannot report the failure this function exists to report.
	return unlink($file);
}

/**
 * Read $file under a shared lock. Plain file_get_contents() takes no lock, so
 * a reader racing a LOCK_EX writer can see a truncated file — and a block
 * update computed from a torn read writes the tear back over the user's own
 * rules. Returns null when the file doesn't exist or the read failed; callers
 * must never treat null as "empty file".
 */
function freshet_uploadmax_read( string $file ): ?string {
	if ( ! is_readable($file) ) {
		return null;
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- LOCK_SH needs a real handle. WP_Filesystem has no locking primitive, so get_contents() here would be the unlocked, torn read this function exists to prevent.
	$fh = fopen($file, 'r');
	if ( ! $fh ) {
		return null;
	}

	flock($fh, LOCK_SH);
	$contents = stream_get_contents($fh);
	flock($fh, LOCK_UN);
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closes the shared-locked handle opened above.
	fclose($fh);

	return false === $contents ? null : $contents;
}

/**
 * Remove every trace of our managed block from $contents: complete BEGIN..END
 * pairs, then any line still carrying a marker — the orphan a half-write or a
 * hand edit leaves behind (our own failure notices tell admins to edit these
 * markers by hand). Orphans must go line-wise: a dangling BEGIN would
 * otherwise pair with the END of a *later* block on the next rewrite and
 * swallow every rule sitting between them.
 *
 * @return string|null Stripped contents (rtrimmed), or null when PCRE failed —
 *                     nothing computed from a null may ever be written back.
 */
function freshet_uploadmax_strip_blocks( string $contents, string $comment ): ?string {
	$begin = preg_quote("{$comment} BEGIN " . FRESHET_UPLOADMAX_MARKER, '/');
	$end   = preg_quote("{$comment} END " . FRESHET_UPLOADMAX_MARKER, '/');

	// The inner match may not cross another BEGIN — a dangling BEGIN pairing
	// with the END of a later, unrelated block is the content-swallowing case.
	$stripped = preg_replace('/\R*' . $begin . '(?:(?!' . $begin . ').)*?' . $end . '\R*/s', "\n", $contents);
	if ( null === $stripped ) {
		return null;
	}

	$stripped = preg_replace('/^.*(?:' . $begin . '|' . $end . ').*\R?/m', '', $stripped);
	if ( null === $stripped ) {
		return null;
	}

	return rtrim($stripped);
}

/**
 * The full new contents of $file with exactly one managed block at the end,
 * everything else preserved. Null when stripping failed (see
 * freshet_uploadmax_strip_blocks()).
 */
function freshet_uploadmax_compose( string $existing, string $comment, string $body ): ?string {
	$other = freshet_uploadmax_strip_blocks($existing, $comment);
	if ( null === $other ) {
		return null;
	}

	$begin = "{$comment} BEGIN " . FRESHET_UPLOADMAX_MARKER;
	$end   = "{$comment} END " . FRESHET_UPLOADMAX_MARKER;
	$block = "{$begin}\n{$body}\n{$end}\n";

	return ( '' === $other ) ? $block : "{$other}\n\n{$block}";
}

/**
 * Insert/update our managed block in $file, preserving everything else.
 * Idempotent. $comment is ';' for ini files, '#' for .htaccess.
 *
 * Read, decision and write happen inside one LOCK_EX critical section (the
 * shape of core's insert_with_markers()): two requests entering together used
 * to race an unlocked read against a truncating write, and the loser could
 * write the user's rules back from a torn read.
 *
 * That is why the handle calls below stay direct and carry a per-line
 * phpcs:ignore instead of moving to WP_Filesystem: it has no locking
 * primitive, so get_contents()/put_contents() here would be two unlocked
 * operations with the race back in between them. The paths that carry no
 * locking contract — freshet_uploadmax_put(), freshet_uploadmax_unlink(), the
 * writability pre-checks — do go through it.
 *
 * @return string 'noop' (already correct), 'written' (changed), 'failed' (write error).
 */
function freshet_uploadmax_write_block( string $file, string $comment, string $body ): string {
	$exists = file_exists($file);

	// Read-only target: never opened for writing (that's a PHP warning), but a
	// block already matching on disk is still a verified 'noop'.
	if ( ! freshet_uploadmax_is_writable( $exists ? $file : dirname($file) ) ) {
		$existing = $exists ? freshet_uploadmax_read($file) : '';
		if ( null === $existing ) {
			return 'failed';
		}
		$new = freshet_uploadmax_compose($existing, $comment, $body);

		return ( null !== $new && $new === $existing ) ? 'noop' : 'failed';
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- one handle serves the whole LOCK_EX critical section below (read, truncate, write). WP_Filesystem has no locking primitive to port this to.
	$fh = fopen($file, 'c+');
	if ( ! $fh ) {
		return 'failed';
	}
	if ( ! flock($fh, LOCK_EX) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- releases the critical-section handle opened above.
		fclose($fh);
		return 'failed';
	}

	$existing = stream_get_contents($fh);
	$new      = ( false === $existing ) ? null : freshet_uploadmax_compose($existing, $comment, $body);

	if ( null === $new ) { // failed read or PCRE failure — write nothing derived from it
		flock($fh, LOCK_UN);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- releases the critical-section handle opened above.
		fclose($fh);
		return 'failed';
	}

	if ( $new === $existing ) {
		flock($fh, LOCK_UN);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- releases the critical-section handle opened above.
		fclose($fh);
		return 'noop';
	}

	rewind($fh);
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- writes inside the LOCK_EX critical section; put_contents() would reopen the file and drop the lock between the read and the write.
	$ok = ftruncate($fh, 0) && strlen($new) === fwrite($fh, $new);
	fflush($fh);
	flock($fh, LOCK_UN);
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- releases the critical-section handle opened above.
	fclose($fh);

	return $ok ? 'written' : 'failed';
}

/**
 * Remove our managed block from $file. Deletes the file when it created it and
 * nothing else remains (only for files we own, i.e. $delete_if_empty). Same
 * locked read-modify-write as freshet_uploadmax_write_block() — including why
 * its handle calls stay direct — and a failed
 * read or strip is 'failed' — the old path coerced a failed read to '' and
 * could then delete or truncate a file it never actually inspected.
 *
 * @return string 'noop' (nothing of ours there), 'removed' (changed), 'failed' (write error).
 */
function freshet_uploadmax_remove_block( string $file, string $comment, bool $delete_if_empty ): string {
	if ( ! is_readable($file) ) {
		return 'noop';
	}

	$existing = freshet_uploadmax_read($file);
	if ( null === $existing ) {
		return 'failed';
	}

	$cleaned = freshet_uploadmax_strip_blocks($existing, $comment);
	if ( null === $cleaned ) {
		return 'failed';
	}

	if ( '' === $cleaned && $delete_if_empty ) {
		return freshet_uploadmax_unlink($file) ? 'removed' : 'failed';
	}

	if ( $cleaned === rtrim($existing) ) {
		return 'noop';
	}

	if ( ! freshet_uploadmax_is_writable($file) ) {
		return 'failed';
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- one handle serves the whole LOCK_EX critical section below (read, truncate, write). WP_Filesystem has no locking primitive to port this to.
	$fh = fopen($file, 'r+');
	if ( ! $fh ) {
		return 'failed';
	}
	if ( ! flock($fh, LOCK_EX) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- releases the critical-section handle opened above.
		fclose($fh);
		return 'failed';
	}

	// Re-read under the lock — the decision above raced other writers.
	$existing = stream_get_contents($fh);
	$cleaned  = ( false === $existing ) ? null : freshet_uploadmax_strip_blocks($existing, $comment);

	if ( null === $cleaned ) {
		flock($fh, LOCK_UN);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- releases the critical-section handle opened above.
		fclose($fh);
		return 'failed';
	}

	if ( $cleaned === rtrim($existing) ) {
		flock($fh, LOCK_UN);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- releases the critical-section handle opened above.
		fclose($fh);
		return 'noop';
	}

	$new = ( '' === $cleaned ) ? '' : $cleaned . "\n";

	rewind($fh);
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- writes inside the LOCK_EX critical section; put_contents() would reopen the file and drop the lock between the read and the write.
	$ok = ftruncate($fh, 0) && strlen($new) === fwrite($fh, $new);
	fflush($fh);
	flock($fh, LOCK_UN);
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- releases the critical-section handle opened above.
	fclose($fh);

	return $ok ? 'removed' : 'failed';
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
 * Prove php_value is allowed *before* touching the live .htaccess: write the
 * same directives into a throwaway subdirectory under ABSPATH and probe a
 * plain text file there. AllowOverride verdicts apply per directory tree, so
 * a 500 here is the same 500 the root block would cause — detected without a
 * single real request ever hitting a broken live file. Only a definitive 500
 * short-circuits to 'unsafe'; 'safe' and 'unknown' still go through the live
 * write + probe + rollback, which stays as the second net.
 *
 * @return string 'safe' | 'unsafe' | 'unknown'
 */
function freshet_uploadmax_htaccess_preflight( int $mb ): string {
	$dir = ABSPATH . 'freshet-uploadmax-probe';

	if ( ! wp_mkdir_p($dir) ) {
		return 'unknown';
	}

	$token = wp_generate_password(32, false, false);

	$wrote = freshet_uploadmax_put("{$dir}/probe.txt", $token)
		&& freshet_uploadmax_put("{$dir}/.htaccess", freshet_uploadmax_htaccess_body($mb) . "\n");

	$verdict = 'unknown';

	if ( $wrote ) {
		$resp = wp_remote_get( site_url('freshet-uploadmax-probe/probe.txt'), [
			'timeout'     => 5,
			'redirection' => 0,
			'sslverify'   => false, // loopback may hit a self-signed/local cert
		] );

		if ( ! is_wp_error($resp) ) {
			$code = (int) wp_remote_retrieve_response_code($resp);

			if ( $code >= 500 ) {
				$verdict = 'unsafe';
			} elseif ( 200 === $code && trim( wp_remote_retrieve_body($resp) ) === $token ) {
				$verdict = 'safe';
			}
		}
	}

	// Leave nothing behind — an abandoned probe dir under a forbidding
	// AllowOverride would 500 on direct hits forever.
	$gone = true;
	foreach ( [ "{$dir}/.htaccess", "{$dir}/probe.txt" ] as $leftover ) {
		if ( file_exists($leftover) ) {
			$gone = freshet_uploadmax_unlink($leftover) && $gone;
		}
	}
	if ( $gone && is_dir($dir) && 2 === count( (array) scandir($dir) ) ) {
		$fs = freshet_uploadmax_fs();

		if ( $fs ) {
			$fs->rmdir($dir);
		} else {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- WP_Filesystem would not initialise here (see freshet_uploadmax_fs()); direct fallback removing our own throwaway probe directory.
			rmdir($dir);
		}
	}

	return $verdict;
}

/**
 * Write the .htaccess block, then verify the new php_value directives didn't
 * take the site down. IfModule guards can't catch an AllowOverride that forbids
 * php_value — that 500s every request — so the order is: preflight in a sandbox
 * directory first (a provably hostile server never sees its live .htaccess
 * touched at all), then write, then probe with a loopback GET and roll back to
 * the exact previous contents if the site stops responding.
 *
 * @return string 'noop' | 'written' | 'failed' | 'unsafe' | 'rollback_failed'
 */
function freshet_uploadmax_apply_htaccess( int $mb ): string {
	$file   = ABSPATH . '.htaccess';
	$body   = freshet_uploadmax_htaccess_body($mb);
	$before = freshet_uploadmax_read($file); // null when absent or unreadable

	// Steady state costs no probes: only an actual content change goes further.
	$new = freshet_uploadmax_compose( (string) $before, '#', $body );
	if ( null === $new ) {
		return 'failed';
	}
	if ( null !== $before && $new === $before ) {
		return 'noop';
	}

	if ( 'unsafe' === freshet_uploadmax_htaccess_preflight($mb) ) {
		return 'unsafe'; // php_value provably 500s here — the live file was never touched
	}

	$result = freshet_uploadmax_write_block($file, '#', $body);
	if ( 'written' !== $result ) {
		return $result; // 'noop' (a concurrent request beat us to it) or 'failed'
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
 * Loopback probe: does the site still return < 500? A WP_Error (loopback
 * blocked, timeout) is treated as "not safe" — for .htaccess we stay
 * conservative, since the failure mode we're guarding against is a total outage.
 *
 * site_url(), not home_url(): with WordPress in its own directory the home
 * page is served from the parent docroot, whose .htaccess we never touched —
 * probing it would pass while every request under ABSPATH (wp-admin, uploads)
 * 500s. site_url() maps to ABSPATH, the directory the block actually governs.
 */
function freshet_uploadmax_site_responds(): bool {
	$resp = wp_remote_get( site_url('/'), [
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
	// Read the directives themselves, not wp_max_upload_size(): that helper runs
	// the 'upload_size_limit' filter, and the classic naive "raise upload" plugin
	// filters the *displayed* number upward — which would hand us a false 'ok'
	// while the server still rejects the file.
	$upload = wp_convert_hr_to_bytes( (string) ini_get('upload_max_filesize') );
	$post   = wp_convert_hr_to_bytes( (string) ini_get('post_max_size') );
	if ( $post <= 0 ) {
		$post = PHP_INT_MAX; // post_max_size=0 disables that limit
	}

	$effective = (int) floor( min($upload, $post) / MB_IN_BYTES );

	if ( $effective >= $target ) {
		update_option('freshet_uploadmax_status', 'ok', false);
		return;
	}

	$applied = get_option('freshet_uploadmax_applied');
	$age     = is_array($applied) ? ( time() - (int) ( $applied['time'] ?? 0 ) ) : 0;

	// Past the cache window and still low → the host is ignoring us or caps
	// lower. The window tracks the host's actual TTL — one configured above the
	// stock 300s would otherwise false-alarm between our window and its refresh.
	$grace = max( 360, (int) ini_get('user_ini.cache_ttl') + 60 );

	update_option('freshet_uploadmax_status', $age > $grace ? 'not_effective' : 'pending', false);
}

/**
 * Apply the limit via the mechanism this host supports. Idempotent; safe to
 * call on every admin load (self-heal) as well as on activation.
 */
function freshet_uploadmax_apply(): void {
	// One shared file at ABSPATH serves every site in a network, but options,
	// notices and capabilities are per-site: a subsite admin (manage_options)
	// could write network-wide PHP config while sibling sites' status records
	// say otherwise. On multisite this is network-admin work; the status tells
	// a per-site activator why nothing happened.
	if ( is_multisite() && ! current_user_can('manage_network_options') ) {
		update_option('freshet_uploadmax_status', 'multisite', false);
		return;
	}

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
	$file = ( 'userini' === $mechanism ) ? ABSPATH . freshet_uploadmax_userini_name() : ABSPATH . '.htaccess';

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
	} elseif ( 'noop' === $result && ! is_array( get_option('freshet_uploadmax_applied') ) ) {
		// Block already on disk but the record of writing it is gone (a
		// deactivate/reactivate cycle deletes it). Without a timestamp the grace
		// window never starts, 'pending' never expires, and a host that ignores
		// .user.ini stays silently "pending" forever instead of reporting
		// not_effective. Seed the clock from now.
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

	// On multisite the self-heal is network-admin work. A subsite admin's screen
	// load must neither write shared config nor be nagged about it — only an
	// explicit per-site *activation* sets the 'multisite' status (see
	// freshet_uploadmax_apply()), so a network that is working stays silent.
	if ( is_multisite() && ! current_user_can('manage_network_options') ) {
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
	// Same network rule as the write path: a subsite deactivation must not strip
	// PHP config every other site on the network shares. Per-site options still
	// get cleaned; the shared files are the network admin's to manage.
	if ( is_multisite() && ! current_user_can('manage_network_options') ) {
		delete_option('freshet_uploadmax_status');
		delete_option('freshet_uploadmax_applied');
		return;
	}

	$failed = [];

	$userini = freshet_uploadmax_userini_name();
	if ( '' !== $userini && 'failed' === freshet_uploadmax_remove_block(ABSPATH . $userini, ';', true) ) { // ours to delete
		$failed[] = ABSPATH . $userini;
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
			$msg = esc_html__('Freshet Upload Max couldn\'t find a supported way to raise the limit on this host (FastCGI/FPM with .user.ini enabled, or mod_php), so it left the upload limit unchanged. Raise it in your server config instead.', 'freshet-uploadmax');
			break;
		case 'htaccess_unsafe':
			$msg = esc_html__('Freshet Upload Max\'s .htaccess directives are rejected by your server (usually a restrictive AllowOverride), so the change was not applied — your site stays online. Raise the upload limit in your server config instead.', 'freshet-uploadmax');
			break;
		case 'multisite':
			$msg = esc_html__('Freshet Upload Max writes one shared, network-wide upload limit on multisite, so it needs a network administrator. Ask a network admin to activate it network-wide, or raise the limit in your server config.', 'freshet-uploadmax');
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

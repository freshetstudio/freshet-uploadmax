<?php

/**
 * Uninstall cleanup for Freshet Upload Max.
 *
 * Removes the plugin's own options. The managed .user.ini / .htaccess block is
 * deactivation's job (it runs before a delete), so nothing on disk is touched
 * here — and a block that deactivation could not remove is already reported
 * by then; the record of it would otherwise sit in wp_options for ever.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('freshet_uploadmax_status');
delete_option('freshet_uploadmax_applied');
delete_option('freshet_uploadmax_cleanup_failed');
